/// <reference types="node" />
/**
 * Header component.
 *
 *   <header data-component="header" data-sticky="scroll"
 *           data-sticky-toggle="#header-menu">
 *     ...
 *   </header>
 *
 * The scroll trigger hides the header on downward scroll and reveals it
 * near the top or on upward scroll (classic app-bar behaviour).
 */
import { LayoutComponent } from './base.js';

export class Header extends LayoutComponent {
  get componentName() { return 'header'; }

  onShow(reason) {
    this.el.classList.remove('iikiti-header--hidden');
  }

  onHide(reason) {
    this.el.classList.add('iikiti-header--hidden');
  }
}
