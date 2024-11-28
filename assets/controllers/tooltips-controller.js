import {Controller} from "@hotwired/stimulus";

export default class TooltipsController extends Controller {
    static defaultOptionsMap = {
        'p.tl_tip': {x: 0, y: 23, useContent: true},
        '#home[title]': {x: 6, y: 42},
        '#tmenu a[title]': {x: 0, y: 42},
        'a[title][class^="group-"]': {x: -6, y: 27},
        'a[title].navigation': {x: 25, y: 32},
        'img[title].gimage': {x: -9, y: 60},
        'img[title]:not(.gimage)': {x: -9, y: 30},
        'a[title].picker-wizard': {x: -4, y: 30},
        'button[title].unselectable': {x: -4, y: 20},
        'button[title]:not(.unselectable)': {x: -9, y: 30},
        'a[title]:not(.picker-wizard)': {x: -9, y: 30},
        'input[title]': {x: -9, y: 30},
        'time[title]': {x: -9, y: 26},
        'span[title]': {x: -9, y: 26},
    };

    activeTargets = new Set();
    removeClickTargetHandlerDelegates = new Map();

    /**
     * There is one controller handling multiple tooltip targets. The tooltip
     * DOM element is shared across targets.
     */
    connect() {
        document.body.appendChild(this.tooltip = this._createTipContainer());

        document.addEventListener('touchstart', this._touchStartDelegate);
    }

    disconnect() {
        this.tooltip.remove();

        document.removeEventListener('touchstart', this._touchStartDelegate);
    }

    tooltipTargetConnected(el) {
        el.addEventListener('mouseenter', (e) => this._showTooltip(e.target, 1000));
        el.addEventListener('touchend', (e) => this._showTooltip(e.target));
        el.addEventListener('mouseleave', (e) => this._hideTooltip(e.target));

        // In case the tooltip target is inside a link or button, also close it
        // when a click happened
        const clickTarget = el.closest('button, a');

        if (clickTarget) {
            const handler = () => this._hideTooltip(el);

            clickTarget.addEventListener('click', handler);
            this.removeClickTargetHandlerDelegates.set(el, () => el.removeEventListener('click', handler));
        }
    }

    tooltipTargetDisconnected(el) {
        if (this.activeTargets.has(el)) {
            this._hideTooltip(el);
        }

        if (this.removeClickTargetHandlerDelegates.has(el)) {
            this.removeClickTargetHandlerDelegates.get(el)();
            this.removeClickTargetHandlerDelegates.delete(el);
        }
    }

    _createTipContainer() {
        const tooltip = document.createElement('div');
        tooltip.setAttribute('role', 'tooltip');
        tooltip.classList.add('tip');
        tooltip.style.position = 'absolute';
        tooltip.style.display = 'none';

        return tooltip;
    }

    _touchStartDelegate = (e) => {
        [...this.activeTargets].filter(el => !el.contains(e.target)).forEach(this._hideTooltip.bind(this))
    };

    _showTooltip(el, delay = 0) {
        const options = this._getOptionsForElement(el);

        if (options === null) {
            return;
        }

        let text;

        if (options.useContent) {
            text = el.innerHTML;
        } else {
            text = el.getAttribute('title');
            el.setAttribute('data-original-title', text);
            el.removeAttribute('title');
            text = text?.replace(/&/g, '&amp;').replace(/</g, '&lt;').replace(/"/g, '&quot;').replace(/'/g, '&apos;');
        }

        if (!text) {
            return;
        }

        clearTimeout(this.timer);
        this.tooltip.style.willChange = 'display,contents';

        this.timer = setTimeout(() => {
            this.activeTargets.add(el);

            const position = el.getBoundingClientRect();
            const rtl = getComputedStyle(el).direction === 'rtl';
            const clientWidth = document.documentElement.clientWidth;

            if ((rtl && position.x < 200) || (!rtl && position.x < (clientWidth - 200))) {
                this.tooltip.style.left = `${(window.scrollX + position.left + options.x)}px`;
                this.tooltip.style.right = 'auto';
                this.tooltip.classList.remove('tip--rtl');
            } else {
                this.tooltip.style.left = 'auto';
                this.tooltip.style.right = `${(clientWidth - window.scrollX - position.right + options.x)}px`;
                this.tooltip.classList.add('tip--rtl');
            }

            this.tooltip.innerHTML = `<div>${text}</div>`;
            this.tooltip.style.top = `${(window.scrollY + position.top + options.y)}px`;
            this.tooltip.style.display = 'block';
            this.tooltip.style.willChange = 'auto';
        }, delay);
    }

    _hideTooltip(el, delay = 0) {
        if (el.hasAttribute('data-original-title')) {
            if (!el.hasAttribute('title')) {
                el.setAttribute('title', el.getAttribute('data-original-title'));
            }

            el.removeAttribute('data-original-title');
        }

        clearTimeout(this.timer);
        this.tooltip.style.willChange = 'auto';

        if (this.tooltip.style.display === 'block') {
            this.activeTargets.delete(el);

            this.tooltip.style.willChange = 'display';
            this.timer = setTimeout(() => {
                this.tooltip.style.display = 'none';
                this.tooltip.style.willChange = 'auto';
            }, delay);
        }
    }

    _getOptionsForElement(el) {
        for (const [criteria, defaultOptions] of Object.entries(TooltipsController.defaultOptionsMap)) {
            if (el.match(criteria)) {
                return defaultOptions;
            }
        }

        return null;
    }

    /**
     * Migrate legacy targets to proper controller targets.
     */
    static afterLoad(identifier, application) {
        const targetSelectors = Object.keys(TooltipsController.defaultOptionsMap);

        const migrateTarget = el => {
            targetSelectors.forEach(target => {
                if (!el.hasAttribute(`data-${identifier}-target`) && el.match(target)) {
                    el.setAttribute(`data-${identifier}-target`, 'tooltip');
                }
            })
        };

        new MutationObserver(function (mutationsList) {
            for (const mutation of mutationsList) {
                if (mutation.type !== 'childList') {
                    continue;
                }

                for (let node of mutation.addedNodes) {
                    if (!(node instanceof HTMLElement)) {
                        continue;
                    }

                    migrateTarget(node)
                }
            }
        }).observe(document, {
            childList: true,
            subtree: true
        });

        // Initially migrate all targets that are already in the DOM
        document.querySelectorAll(targetSelectors.join(',')).forEach(el => migrateTarget(el));
    }
}
