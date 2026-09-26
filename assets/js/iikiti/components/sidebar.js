/// <reference types="node" />
/**
 * Sidebar component.
 *
 *   <aside data-component="sidebar" data-sticky="scroll,edge,button"
 *          data-sticky-toggle="#sidebar-toggle" data-sticky-target=".sidebar-edge">
 *     ...
 *   </aside>
 *
 * - On desktop: `position: sticky` stays in flow; reveals on edge proximity
 *   or hover-target; hides on scroll-down.
 * - On mobile: the toggle button shows an overlay drawer
 *   (`.iikiti-sidebar--open`) covering the viewport, with a backdrop.
 *
 * Collapsible: clicking `[data-iikiti-toggle="collapse"]` toggles a
 * `.iikiti-sidebar--collapsed` class (icons-only rail).
 */
import { LayoutComponent } from './base.js';

export class Sidebar extends LayoutComponent {
	constructor(el, opts = {}) {
		super(el, opts);
		this._wireCollapse();
		window.addEventListener('resize', this._onResizeMobile.bind(this));
		this._updateMobile();
	}

	get componentName() { return 'sidebar'; }

	_wireCollapse() {
		const { el } = this;
		if (!el) return;
		const btn = el.querySelector('[data-iikiti-toggle="collapse"]');
		if (btn) btn.addEventListener('click', () => this._toggleCollapse());
	}

	_toggleCollapse() {
		const { el } = this;
		if (!el) return;
		const collapsed = el.classList.toggle('iikiti-sidebar--collapsed');
		this.onCollapse?.(collapsed);
		this.opts.onCollapse?.(collapsed);
	}

	onCollapse(collapsed) {}

	_ensureOverlay() {
		if (this._overlay || !this.el?.parentNode) return;
		const overlay = document.createElement('div');
		overlay.className = 'iikiti-sidebar__overlay';
		overlay.setAttribute('aria-hidden', 'true');
		overlay.addEventListener('click', () => this._setOpen(false));
		this.el.parentNode.insertBefore(overlay, this.el.nextSibling);
		this._overlay = overlay;
	}

	_onResizeMobile() {
		this._updateMobile();
	}

	_updateMobile() {
		const { el } = this;
		if (!el) return;
		const isMobile = window.matchMedia('(max-width: 767px)').matches;
		if (isMobile) {
			this._ensureOverlay();
			el.classList.add('iikiti-sidebar--mobile');
			// Drawers start closed when entering mobile mode.
			this.sticky?.setVisible(false);
			if (this._overlay) this._overlay.style.display = 'none';
		} else {
			el.classList.remove('iikiti-sidebar--mobile', 'iikiti-sidebar--open');
			el.style.position = '';
			el.style.inset = '';
			el.style.zIndex = '';
			this.sticky?.setVisible(true);
			if (this._overlay) this._overlay.style.display = 'none';
		}
	}

	_setOpen(open) {
		const { el, _overlay } = this;
		if (!el) return;
		if (open) {
			el.classList.add('iikiti-sidebar--open');
			if (_overlay) _overlay.style.display = 'block';
		} else {
			el.classList.remove('iikiti-sidebar--open');
			if (_overlay) _overlay.style.display = 'none';
		}
	}

	onShow() { this._setOpen(true); }
	onHide() { this._setOpen(false); }

	destroy() {
		super.destroy();
		window.removeEventListener('resize', this._onResizeMobile);
		if (this._overlay) this._overlay.remove();
		if (this.el) {
			this.el.classList.remove(
				'iikiti-sidebar--mobile', 'iikiti-sidebar--open', 'iikiti-sidebar--collapsed',
				'iikiti-hidden', 'iikiti-translate-in',
			);
			this.el.style.position = '';
			this.el.style.inset = '';
			this.el.style.zIndex = '';
		}
		document.body.style.overflow = '';
	}
}
