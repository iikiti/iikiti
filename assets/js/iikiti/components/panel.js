/// <reference types="node" />
/**
 * Generic panel component.
 */
import { LayoutComponent } from './base.js';

export class Panel extends LayoutComponent {
  get componentName() { return 'panel'; }
}
