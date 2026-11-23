import VideoEmbedPreview from './VideoEmbedPreview.svelte';
import SocialEmbedPreview from './SocialEmbedPreview.svelte';

export function registerEmbedsEditor(editor) {
	if (!editor?.registerBlockPreview) return;
	editor.registerBlockPreview('video_embed', VideoEmbedPreview);
	editor.registerBlockPreview('social_embed', SocialEmbedPreview);
}

if (typeof window !== 'undefined') {
	const register = () => registerEmbedsEditor(window.iikiti?.editor);
	if (window.iikiti?.editor?.registerBlockPreview) {
		register();
	} else if (typeof window.iikiti?.whenReady === 'function') {
		window.iikiti.whenReady('editor.sidebar').then(register);
	}
}
