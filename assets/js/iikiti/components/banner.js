/// <reference types="node" />
/**
 * Banner component.
 *
 *   <div data-component="banner" data-position="sticky" data-sticky="scroll"
 *        data-dismissible="true" data-iikiti-source="/api/banner">
 *     <div data-iikiti-content></div>
 *   </div>
 */
import { LayoutComponent } from './base.js';

export class Banner extends LayoutComponent {
  constructor(el, opts = {}) {
    super(el, opts);
    this._wireDismiss();
  }

  get componentName() { return 'banner'; }

  _wireDismiss() {
    const { el } = this;
    if (!el) return;
    const dismissible = el.dataset.dismissible === 'true' || this.opts.dismissible;
    if (!dismissible) return;
    const btn = el.querySelector('[data-iikiti-dismiss]');
    if (btn) {
      btn.addEventListener('click', () => this._dismiss());
    } else {
      const close = document.createElement('button');
      close.type = 'button';
      close.setAttribute('aria-label', 'Dismiss');
      close.className = 'iikiti-banner__dismiss';
      close.innerHTML = '<!-- X -->';
      close.addEventListener('click', () => this._dismiss());
      el.appendChild(close);
    }
  }

  _dismiss() {
    const { el } = this;
    if (!el) return;
    el.setAttribute('aria-hidden', 'true');
    el.style.display = 'none';
    this.onDismiss?.();
    this.opts.onDismiss?.();
  }

  onDismiss() {}
}
