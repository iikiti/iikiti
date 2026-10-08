<script lang="ts">
	import { ensureTemporal, formatDateTime, hasNativeTemporal } from '../../../js/iikiti/time/temporal.js';

	/**
	 * Displays an ISO instant in the user's time zone.
	 *
	 * The definer picks what is shown (`kind`) and how long it is (`style`).
	 * Until Temporal is available (polyfill loading) the raw ISO string is shown.
	 */
	interface Props {
		value: string | null | undefined;
		kind?: 'date' | 'time' | 'datetime';
		style?: 'short' | 'medium' | 'long';
	}

	let { value, kind = 'datetime', style = 'medium' }: Props = $props();

	let ready = $state(hasNativeTemporal());

	$effect(() => {
		if (!ready) {
			ensureTemporal().then(() => (ready = true)).catch(() => (ready = false));
		}
	});

	const display = $derived.by(() => {
		if (!value) return '';
		if (!ready) return value;
		try {
			return formatDateTime(value, { kind, style });
		} catch {
			return value;
		}
	});
</script>

<time datetime={value ?? undefined}>{display}</time>
