<script lang="ts">
	import type { ComponentStatus } from '$types';

	interface Props {
		status?: ComponentStatus;
		outline?: boolean;
	}

	let { status = 'primary', outline = false, children }: Props = $props();

	const solidClass: Record<string, string> = {
		success: 'admin-badge-success',
		warning: 'admin-badge-warning',
		danger: 'admin-badge-danger',
		info: 'admin-badge-info',
		primary: 'admin-badge-primary',
	};
	const outlineClass: Record<string, string> = {
		success: 'admin-badge-outline-success',
		warning: 'admin-badge-outline-warning',
		danger: 'admin-badge-outline-danger',
		info: 'admin-badge-outline-info',
		primary: 'admin-badge-outline-primary',
	};
</script>

<span class="admin-badge {outline ? outlineClass[status] : solidClass[status]}">
	{@render children?.()}
</span>
