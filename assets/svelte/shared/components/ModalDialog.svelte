<script lang="ts">
	import type { Snippet } from 'svelte';
	import Dialog from './Dialog.svelte';

	/**
	 * Modal dialog that takes over / covers the rest of the site.
	 *
	 * Thin wrapper around the base {@link Dialog} that calls
	 * `dialog.showModal()`, moving the dialog into the browser top layer with a
	 * `::backdrop` that covers the page. Centred, not draggable/resizable.
	 */
	interface Props {
		title: string;
		open?: boolean;
		onClose?: () => void;
		width?: number;
		closeable?: boolean;
		children: Snippet;
	}

	let { title, open = true, onClose, width = 480, closeable = true, children }: Props = $props();
</script>

<Dialog
	{title}
	{open}
	{onClose}
	modal
	{width}
	{closeable}
	resizable={false}
>
	{@render children()}
</Dialog>
