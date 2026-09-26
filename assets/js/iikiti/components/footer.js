/// <reference types="node" />
/**
 * Footer component.
 *
 *   <footer data-component="footer" data-position="static">
 *     ...
 *   </footer>
 *
 * Supports `sticky` (stick to viewport bottom) or `absolute` positioning,
 * and dynamic content via `data-iikiti-source`.
 */
import { LayoutComponent } from './base.js';

export class Footer extends LayoutComponent {
  get componentName() { return 'footer'; }

  onShow(reason) {
    this.el.classList.remove('iikiti-footer--hidden');
  }

  onHide(reason) {
    this.el.classList.add('iikiti-footer--hidden');
  }
}
