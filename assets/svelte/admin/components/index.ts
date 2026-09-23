/**
 * Barrel export for the core admin component library.
 *
 * This module is aliased as `@iikiti/admin` in the webpack configuration so
 * that plugins can import core components in their own UI bundles:
 *
 * ```ts
 * import { DataTable, PageHeader } from '@iikiti/admin';
 * ```
 */

export { default as AdminLayout } from './AdminLayout.svelte';
export { default as DataTable } from './DataTable.svelte';
export { default as PageHeader } from './PageHeader.svelte';
export { default as LoadingState } from './LoadingState.svelte';
export { default as ErrorBoundary } from './ErrorBoundary.svelte';
export { default as EmptyState } from './EmptyState.svelte';
export { default as Button } from './Button.svelte';
export { default as Badge } from './Badge.svelte';
export { default as Tabs } from './Tabs.svelte';
export { default as Breadcrumb } from './Breadcrumb.svelte';
export { default as Pagination } from './Pagination.svelte';
export { default as SearchForm } from './SearchForm.svelte';
export { default as Dialog } from './Dialog.svelte';
export { default as DetailView } from './DetailView.svelte';
export { default as Card } from './Card.svelte';
export { default as Form } from './Form.svelte';
export { default as FormField } from './FormField.svelte';
export { default as TextInput } from './TextInput.svelte';
export { default as TextareaInput } from './TextareaInput.svelte';
export { default as SelectInput } from './SelectInput.svelte';
export { default as CheckboxInput } from './CheckboxInput.svelte';
export { default as ToggleInput } from './ToggleInput.svelte';
export { default as Popover } from './Popover.svelte';
export { default as Toast } from './Toast.svelte';
export { default as NotificationCenter } from './NotificationCenter.svelte';
