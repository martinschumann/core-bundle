<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Search\Backend\Provider;

use Contao\CoreBundle\Config\ResourceFinder;
use Contao\CoreBundle\DataContainer\RecordLabeler;
use Contao\CoreBundle\Framework\ContaoFramework;
use Contao\CoreBundle\Search\Backend\Document;
use Contao\CoreBundle\Search\Backend\Event\FormatTableDataContainerDocumentEvent;
use Contao\CoreBundle\Search\Backend\Hit;
use Contao\CoreBundle\Search\Backend\ReindexConfig;
use Contao\CoreBundle\Security\ContaoCorePermissions;
use Contao\CoreBundle\Security\DataContainer\ReadAction;
use Contao\DC_Table;
use Contao\DcaLoader;
use Doctrine\DBAL\ArrayParameterType;
use Doctrine\DBAL\Connection;
use Doctrine\DBAL\ParameterType;
use Doctrine\DBAL\Query\QueryBuilder;
use Symfony\Component\EventDispatcher\EventDispatcherInterface;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Security\Core\Authentication\Token\TokenInterface;
use Symfony\Component\Security\Core\Authorization\AccessDecisionManagerInterface;

/**
 * @experimental
 */
class TableDataContainerProvider implements ProviderInterface
{
    public const TYPE_PREFIX = 'contao.db.';

    public function __construct(
        private readonly ContaoFramework $contaoFramework,
        private readonly ResourceFinder $resourceFinder,
        private readonly Connection $connection,
        private readonly RecordLabeler $recordLabeler,
        private readonly AccessDecisionManagerInterface $accessDecisionManager,
        private readonly EventDispatcherInterface $eventDispatcher,
    ) {
    }

    public function supportsType(string $type): bool
    {
        return str_starts_with($type, self::TYPE_PREFIX);
    }

    /**
     * @return iterable<Document>
     */
    public function updateIndex(ReindexConfig $config): iterable
    {
        foreach ($this->getTables($config) as $table) {
            try {
                $dcaLoader = new DcaLoader($table);
                $dcaLoader->load();
            } catch (\Exception) {
                continue;
            }

            if (!isset($GLOBALS['TL_DCA'][$table]['config']['dataContainer'])) {
                continue;
            }

            // We intentionally do not update child classes of DC_Table here because they
            // could have different logic (like DC_Multilingual) or a different permission
            // concept etc.
            if (DC_Table::class !== $GLOBALS['TL_DCA'][$table]['config']['dataContainer']) {
                continue;
            }

            foreach ($this->findDocuments($table, $config) as $document) {
                yield $document;
            }
        }
    }

    public function convertDocumentToHit(Document $document): Hit|null
    {
        $document = $this->addCurrentRowToDocumentIfNotAlreadyLoaded($document);
        $row = $document->getMetadata()['row'] ?? null;

        // Entry does not exist anymore -> no hit
        if (null === $row) {
            return null;
        }

        // TODO: service for view and edit URLs
        $viewUrl = 'https://todo.com?view='.$document->getId();
        $editUrl = 'https://todo.com?edit='.$document->getId();

        $title = $this->recordLabeler->getLabel(\sprintf('contao.db.%s.id', $this->getTableFromDocument($document)), $row);

        return (new Hit($document, $title, $viewUrl))
            ->withEditUrl($editUrl)
            ->withContext($document->getSearchableContent())
            ->withMetadata(['row' => $row]) // Used for permission checks in isHitGranted()
        ;
    }

    public function isDocumentGranted(TokenInterface $token, Document $document): bool
    {
        $document = $this->addCurrentRowToDocumentIfNotAlreadyLoaded($document);
        $row = $document->getMetadata()['row'] ?? null;

        // Entry does not exist anymore -> no access
        if (null === $row) {
            return false;
        }

        $table = $this->getTableFromDocument($document);

        return $this->accessDecisionManager->decide(
            $token,
            [ContaoCorePermissions::DC_PREFIX.$table],
            new ReadAction($table, $row),
        );
    }

    private function addCurrentRowToDocumentIfNotAlreadyLoaded(Document $document): Document
    {
        if (isset($document->getMetadata()['row'])) {
            return $document;
        }

        $row = $this->loadRow($this->getTableFromDocument($document), (int) $document->getId());

        return $document->withMetadata(['row' => false === $row ? null : $row]);
    }

    private function getTableFromDocument(Document $document): string
    {
        return $document->getMetadata()['table'] ?? '';
    }

    /**
     * @return array<int, string>
     */
    private function getTables(ReindexConfig $config): array
    {
        $this->contaoFramework->initialize();

        $files = $this->resourceFinder->findIn('dca')->depth(0)->files()->name('*.php');

        $tables = array_unique(array_values(array_map(
            static fn (SplFileInfo $input) => str_replace('.php', '', $input->getRelativePathname()),
            iterator_to_array($files->getIterator()),
        )));

        // No document ID limits, consider all tables
        if ($config->getLimitedDocumentIds()->isEmpty()) {
            return $tables;
        }

        // Only consider tables that were asked for
        return array_filter($tables, fn (string $table): bool => $config->getLimitedDocumentIds()->hasType($this->getTypeFromTable($table)));
    }

    private function findDocuments(string $table, ReindexConfig $reindexConfig): \Generator
    {
        if (!isset($GLOBALS['TL_DCA'][$table]['fields'])) {
            return [];
        }

        $fieldsConfig = $GLOBALS['TL_DCA'][$table]['fields'];

        $searchableFields = array_filter(
            $fieldsConfig,
            static fn (array $config): bool => isset($config['search']) && true === $config['search'],
        );

        $qb = $this->createQueryBuilderForTable($table);

        if ($reindexConfig->getUpdateSince() && isset($GLOBALS['TL_DCA'][$table]['fields']['tstamp'])) {
            $qb->andWhere('tstamp <= ', $qb->createNamedParameter($reindexConfig->getUpdateSince()));
        }

        if ($documentIds = $reindexConfig->getLimitedDocumentIds()->getDocumentIdsForType($this->getTypeFromTable($table))) {
            $qb->expr()->in('id', $qb->createNamedParameter($documentIds, ArrayParameterType::STRING));
        }

        foreach ($qb->executeQuery()->iterateAssociative() as $row) {
            $document = $this->createDocumentFromRow($table, $row, $fieldsConfig, $searchableFields);

            if ($document) {
                yield $document;
            }
        }
    }

    private function createDocumentFromRow(string $table, array $row, array $fieldsConfig, array $searchableFields): Document|null
    {
        $searchableContent = $this->extractSearchableContent($row, $fieldsConfig, $searchableFields);

        if ('' === $searchableContent) {
            return null;
        }

        return (new Document((string) $row['id'], $this->getTypeFromTable($table), $searchableContent))->withMetadata(['table' => $table]);
    }

    private function getTypeFromTable(string $table): string
    {
        return self::TYPE_PREFIX.$table;
    }

    private function extractSearchableContent(array $row, array $fieldsConfig, array $searchableFields): string
    {
        $searchableContent = [];

        foreach (array_keys($searchableFields) as $field) {
            if (isset($row[$field])) {
                $event = new FormatTableDataContainerDocumentEvent($row[$field], $fieldsConfig[$field] ?? []);
                $this->eventDispatcher->dispatch($event);
                $searchableContent[] = $event->getSearchableContent();
            }
        }

        return implode(' ', array_filter(array_unique($searchableContent)));
    }

    private function loadRow(string $table, int $id): array|false
    {
        $qb = $this->createQueryBuilderForTable($table);

        return $qb
            ->andWhere('id = '.$qb->createNamedParameter($id, ParameterType::INTEGER))
            ->fetchAssociative()
        ;
    }

    private function createQueryBuilderForTable(string $table): QueryBuilder
    {
        return $this->connection
            ->createQueryBuilder()
            ->select('*')
            ->from($table)
        ;
    }
}
