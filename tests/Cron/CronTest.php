<?php

declare(strict_types=1);

/*
 * This file is part of Contao.
 *
 * (c) Leo Feyer
 *
 * @license LGPL-3.0-or-later
 */

namespace Contao\CoreBundle\Tests\Cron;

use Contao\CoreBundle\Cron\Cron;
use Contao\CoreBundle\Cron\CronJob;
use Contao\CoreBundle\Entity\CronJob as CronJobEntity;
use Contao\CoreBundle\Fixtures\Cron\TestCronJob;
use Contao\CoreBundle\Fixtures\Cron\TestInvokableCronJob;
use Contao\CoreBundle\Repository\CronJobRepository;
use Contao\CoreBundle\Tests\TestCase;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Cache\CacheItemPoolInterface;
use Psr\Log\LoggerInterface;
use Symfony\Component\Cache\Adapter\ArrayAdapter;
use Symfony\Component\Lock\LockFactory;
use Symfony\Component\Lock\SharedLockInterface;

class CronTest extends TestCase
{
    public function testExecutesAddedCronJob(): void
    {
        $cronjob = $this->createMock(TestCronJob::class);
        $cronjob
            ->expects($this->once())
            ->method('onHourly')
        ;

        $cron = new Cron(
            fn () => $this->createMock(CronJobRepository::class),
            fn () => $this->createMock(EntityManagerInterface::class),
            $this->createMock(CacheItemPoolInterface::class),
            $this->createLockFactory(),
        );

        $cron->addCronJob(new CronJob($cronjob, '@hourly', 'onHourly'));
        $cron->run(Cron::SCOPE_CLI);
    }

    public function testExecutesSingleCronJob(): void
    {
        $cronjob = $this->createMock(TestCronJob::class);
        $cronjob
            ->expects($this->once())
            ->method('onHourly')
        ;

        $cron = new Cron(
            fn () => $this->createMock(CronJobRepository::class),
            fn () => $this->createMock(EntityManagerInterface::class),
            $this->createMock(CacheItemPoolInterface::class),
            $this->createLockFactory(),
        );

        $cron->addCronJob(new CronJob($cronjob, '@hourly', 'onHourly'));
        $cron->runJob($cronjob::class.'::onHourly', Cron::SCOPE_CLI);
    }

    public function testLoggingOfExecutedCronJobs(): void
    {
        $cronjob = $this
            ->getMockBuilder(TestCronJob::class)
            ->setMockClassName('TestCronJob')
            ->getMock()
        ;

        $cronjob
            ->expects($this->once())
            ->method('onMinutely')
        ;

        $cronjob
            ->expects($this->once())
            ->method('onHourly')
        ;

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->exactly(2))
            ->method('debug')
            ->withConsecutive(
                ['Executing cron job "TestCronJob::onMinutely"'],
                ['Executing cron job "TestCronJob::onHourly"'],
            )
        ;

        $cron = new Cron(
            fn () => $this->createMock(CronJobRepository::class),
            fn () => $this->createMock(EntityManagerInterface::class),
            $this->createMock(CacheItemPoolInterface::class),
            $this->createLockFactory(),
            $logger,
        );

        $cron->addCronJob(new CronJob($cronjob, '* * * * *', 'onMinutely'));
        $cron->addCronJob(new CronJob($cronjob, '0 * * * *', 'onHourly'));
        $cron->run(Cron::SCOPE_CLI);
    }

    public function testUpdatesCronEntities(): void
    {
        $entity = $this->createMock(CronJobEntity::class);
        $entity
            ->expects($this->once())
            ->method('setLastRun')
        ;

        $entity
            ->method('getName')
            ->willReturn('UpdateEntitiesCron::onHourly')
        ;

        $entity
            ->method('getLastRun')
            ->willReturn((new \DateTime())->modify('-1 hours'))
        ;

        $repository = $this->createMock(CronJobRepository::class);
        $repository
            ->expects($this->once())
            ->method('__call')
            ->with($this->equalTo('findOneByName'), $this->equalTo(['UpdateEntitiesCron::onHourly']))
            ->willReturn($entity)
        ;

        $cronjob = $this
            ->getMockBuilder(TestCronJob::class)
            ->setMockClassName('UpdateEntitiesCron')
            ->getMock()
        ;

        $cronjob
            ->expects($this->once())
            ->method('onHourly')
        ;

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager
            ->expects($this->once())
            ->method('flush')
        ;

        $cron = new Cron(
            static fn () => $repository,
            static fn () => $manager,
            $this->createMock(CacheItemPoolInterface::class),
            $this->createLockFactory(),
        );

        $cron->addCronJob(new CronJob($cronjob, '@hourly', 'onHourly'));
        $cron->run(Cron::SCOPE_CLI);
    }

    public function testSetsScope(): void
    {
        $cronjob = $this->createMock(TestInvokableCronJob::class);
        $cronjob
            ->expects($this->once())
            ->method('__invoke')
            ->with(Cron::SCOPE_CLI)
        ;

        $cron = new Cron(
            fn () => $this->createMock(CronJobRepository::class),
            fn () => $this->createMock(EntityManagerInterface::class),
            $this->createMock(CacheItemPoolInterface::class),
            $this->createLockFactory(),
        );

        $cron->addCronJob(new CronJob($cronjob, '@hourly'));
        $cron->run(Cron::SCOPE_CLI);
    }

    public function testInvalidArgumentExceptionForScope(): void
    {
        $cron = new Cron(
            fn () => $this->createMock(CronJobRepository::class),
            fn () => $this->createMock(EntityManagerInterface::class),
            $this->createMock(CacheItemPoolInterface::class),
            $this->createMock(LockFactory::class),
        );

        try {
            $cron->run(Cron::SCOPE_CLI);
            $cron->run(Cron::SCOPE_WEB);
        } catch (\InvalidArgumentException) {
            $this->fail();
        }

        $this->expectException(\InvalidArgumentException::class);
        $cron->run('invalid_scope');
    }

    public function testDoesNotInstantiateDependenciesInConstructor(): void
    {
        $cron = new Cron(
            static function (): never {
                throw new \LogicException();
            },
            static function (): never {
                throw new \LogicException();
            },
            $this->createMock(CacheItemPoolInterface::class),
            $this->createMock(LockFactory::class),
        );

        $this->expectException(\LogicException::class);

        $cron->run(Cron::SCOPE_CLI);
    }

    public function testDoesNotRunCronJobIfAlreadyRun(): void
    {
        $entity = $this->createMock(CronJobEntity::class);
        $entity
            ->method('getName')
            ->willReturn('UpdateEntitiesCron::onHourly')
        ;

        $entity
            ->method('getLastRun')
            ->willReturn((new \DateTime())->modify('+2 hours'))
        ;

        $entity
            ->expects($this->never())
            ->method('setLastRun')
        ;

        $repository = $this->createMock(CronJobRepository::class);
        $repository
            ->expects($this->once())
            ->method('__call')
            ->with($this->equalTo('findOneByName'), $this->equalTo(['UpdateEntitiesCron::onHourly']))
            ->willReturn($entity)
        ;

        $cronjob = $this
            ->getMockBuilder(TestCronJob::class)
            ->setMockClassName('UpdateEntitiesCron')
            ->getMock()
        ;

        $cronjob
            ->expects($this->never())
            ->method('onHourly')
        ;

        $cron = new Cron(
            static fn () => $repository,
            fn () => $this->createMock(EntityManagerInterface::class),
            $this->createMock(CacheItemPoolInterface::class),
            $this->createLockFactory(),
        );

        $cron->addCronJob(new CronJob($cronjob, '@hourly', 'onHourly'));
        $cron->run(Cron::SCOPE_CLI);
    }

    public function testForcesCronJobToBeRunIfAlreadyRun(): void
    {
        $entity = $this->createMock(CronJobEntity::class);
        $entity
            ->method('getName')
            ->willReturn('UpdateEntitiesCron::onHourly')
        ;

        $entity
            ->method('getLastRun')
            ->willReturn((new \DateTime())->modify('-1 minute'))
        ;

        $entity
            ->expects($this->once())
            ->method('setLastRun')
        ;

        $repository = $this->createMock(CronJobRepository::class);
        $repository
            ->expects($this->once())
            ->method('__call')
            ->with($this->equalTo('findOneByName'), $this->equalTo(['UpdateEntitiesCron::onHourly']))
            ->willReturn($entity)
        ;

        $cronjob = $this
            ->getMockBuilder(TestCronJob::class)
            ->setMockClassName('UpdateEntitiesCron')
            ->getMock()
        ;

        $cronjob
            ->expects($this->once())
            ->method('onHourly')
        ;

        $cron = new Cron(
            static fn () => $repository,
            fn () => $this->createMock(EntityManagerInterface::class),
            $this->createMock(CacheItemPoolInterface::class),
            $this->createLockFactory(),
        );

        $cron->addCronJob(new CronJob($cronjob, '@hourly', 'onHourly'));
        $cron->run(Cron::SCOPE_CLI, true);
    }

    public function testMinutelyCronJob(): void
    {
        $lastRun = (new \DateTime())->modify('-1 hours');

        $entity = $this->createMock(CronJobEntity::class);
        $entity
            ->expects($this->once())
            ->method('setLastRun')
            ->withConsecutive([$this->anything()])
        ;

        $entity
            ->method('getName')
            ->willReturn('Contao\CoreBundle\Cron\Cron::updateMinutelyCliCron')
        ;

        $entity
            ->method('getLastRun')
            ->willReturn($lastRun)
        ;

        $repository = $this->createMock(CronJobRepository::class);
        $repository
            ->expects($this->once())
            ->method('__call')
            ->with($this->equalTo('findOneByName'), $this->equalTo(['Contao\CoreBundle\Cron\Cron::updateMinutelyCliCron']))
            ->willReturn($entity)
        ;

        $logger = $this->createMock(LoggerInterface::class);
        $logger
            ->expects($this->exactly(2))
            ->method('debug')
            ->withConsecutive(
                ['Executing cron job "Contao\CoreBundle\Cron\Cron::updateMinutelyCliCron"'],
                ['Asynchronous cron job "Contao\CoreBundle\Cron\Cron::updateMinutelyCliCron" finished successfully'],
                ['Executing cron job "Contao\CoreBundle\Cron\Cron::updateMinutelyCliCron"'],
                // No completion log, as we're skipping
            )
        ;

        $cache = new ArrayAdapter();

        $cron = new Cron(
            static fn () => $repository,
            fn () => $this->createMock(EntityManagerInterface::class),
            $cache,
            $this->createLockFactory(),
            $logger,
        );

        $cron->addCronJob(new CronJob($cron, '* * * * *', 'updateMinutelyCliCron'));

        $this->assertFalse($cron->hasMinutelyCliCron());

        $cron->run(Cron::SCOPE_CLI);

        $this->assertTrue($cron->hasMinutelyCliCron());

        $cache->clear();

        $this->assertFalse($cron->hasMinutelyCliCron());
    }

    public function testMinutelyCronJobResetsLastRunInWebScope(): void
    {
        $lastRun = (new \DateTime())->modify('-1 hours');

        $entity = $this->createMock(CronJobEntity::class);
        $entity
            ->expects($this->exactly(2))
            ->method('setLastRun')
            ->withConsecutive([$this->anything()], [$lastRun])
        ;

        $entity
            ->method('getName')
            ->willReturn('Contao\CoreBundle\Cron\Cron::updateMinutelyCliCron')
        ;

        $entity
            ->method('getLastRun')
            ->willReturn($lastRun)
        ;

        $repository = $this->createMock(CronJobRepository::class);
        $repository
            ->expects($this->exactly(2))
            ->method('__call')
            ->with($this->equalTo('findOneByName'), $this->equalTo(['Contao\CoreBundle\Cron\Cron::updateMinutelyCliCron']))
            ->willReturn($entity)
        ;

        $cache = new ArrayAdapter();

        $cron = new Cron(
            static fn () => $repository,
            fn () => $this->createMock(EntityManagerInterface::class),
            $cache,
            $this->createLockFactory(),
        );

        $cron->addCronJob(new CronJob($cron, '* * * * *', 'updateMinutelyCliCron'));
        $cron->run(Cron::SCOPE_WEB);
    }

    public function testResetsLastRunForSkippedCronJobs(): void
    {
        $lastRun = (new \DateTime())->modify('-1 hours');

        $entity = $this->createMock(CronJobEntity::class);
        $entity
            ->expects($this->exactly(2))
            ->method('setLastRun')
            ->withConsecutive([$this->anything()], [$lastRun])
        ;

        $entity
            ->method('getName')
            ->willReturn('Contao\CoreBundle\Fixtures\Cron\TestCronJob::skippingMethod')
        ;

        $entity
            ->method('getLastRun')
            ->willReturn($lastRun)
        ;

        $repository = $this->createMock(CronJobRepository::class);
        $repository
            ->expects($this->exactly(2))
            ->method('__call')
            ->with(
                $this->equalTo('findOneByName'),
                $this->equalTo(['Contao\CoreBundle\Fixtures\Cron\TestCronJob::skippingMethod']),
            )
            ->willReturn($entity)
        ;

        $cronjob = new TestCronJob();

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager
            ->expects($this->exactly(2))
            ->method('flush')
        ;

        $cron = new Cron(
            static fn () => $repository,
            static fn () => $manager,
            new ArrayAdapter(),
            $this->createLockFactory(),
        );

        $cron->addCronJob(new CronJob($cronjob, '@hourly', 'skippingMethod'));
        $cron->run(Cron::SCOPE_CLI);
    }

    public function testResetsLastRunForSkippedAsyncCronJobs(): void
    {
        $lastRun = (new \DateTime())->modify('-1 hours');

        $entity = $this->createMock(CronJobEntity::class);
        $entity
            ->expects($this->exactly(2))
            ->method('setLastRun')
            ->withConsecutive([$this->anything()], [$lastRun])
        ;

        $entity
            ->method('getName')
            ->willReturn('Contao\CoreBundle\Fixtures\Cron\TestCronJob::skippingAsyncMethod')
        ;

        $entity
            ->method('getLastRun')
            ->willReturn($lastRun)
        ;

        $repository = $this->createMock(CronJobRepository::class);
        $repository
            ->expects($this->exactly(2))
            ->method('__call')
            ->with(
                $this->equalTo('findOneByName'),
                $this->equalTo(['Contao\CoreBundle\Fixtures\Cron\TestCronJob::skippingAsyncMethod']),
            )
            ->willReturn($entity)
        ;

        $cronjob = new TestCronJob();

        $manager = $this->createMock(EntityManagerInterface::class);
        $manager
            ->expects($this->exactly(2))
            ->method('flush')
        ;

        $cron = new Cron(
            static fn () => $repository,
            static fn () => $manager,
            $this->createMock(CacheItemPoolInterface::class),
            $this->createLockFactory(),
        );

        $cron->addCronJob(new CronJob($cronjob, '@hourly', 'skippingAsyncMethod'));
        $cron->run(Cron::SCOPE_CLI);
    }

    private function createLockFactory(bool $locked = false): LockFactory
    {
        $lock = $this->createMock(SharedLockInterface::class);
        $lock
            ->expects($this->once())
            ->method('acquire')
            ->willReturn(!$locked)
        ;

        $lock
            ->expects($this->exactly((int) !$locked))
            ->method('release')
        ;

        $lockFactory = $this->createMock(LockFactory::class);
        $lockFactory
            ->expects($this->once())
            ->method('createLock')
            ->willReturn($lock)
        ;

        return $lockFactory;
    }
}
