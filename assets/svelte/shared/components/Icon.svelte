<script lang="ts">
  import type { Component } from 'svelte';
  import * as Lucide from '@lucide/svelte';

  interface Props {
    /** Kebab-case Lucide icon name, e.g. `layout-dashboard`. */
    name: string;
    size?: number;
    class?: string;
  }

  let { name, size = 18, class: cls = '' }: Props = $props();

  const map: Record<string, Component<any>> = {
    'layout-dashboard': Lucide.LayoutDashboard,
    'users': Lucide.Users,
    'shield': Lucide.Shield,
    'server': Lucide.Server,
    'globe': Lucide.Globe,
    'layout-template': Lucide.LayoutTemplate,
    'panels-top-left': Lucide.PanelsTopLeft,
    'panel-top': Lucide.PanelTop,
    'layers': Lucide.Layers,
    'puzzle': Lucide.Puzzle,
    'search': Lucide.Search,
    'clock': Lucide.Clock,
    'history': Lucide.History,
    'edit': Lucide.Pencil,
    'pencil': Lucide.Pencil,
    'trash': Lucide.Trash,
    'trash-2': Lucide.Trash2,
    'eye': Lucide.Eye,
    'save': Lucide.Save,
    'plus': Lucide.Plus,
    'x': Lucide.X,
    'menu': Lucide.Menu,
    'sun': Lucide.Sun,
    'moon': Lucide.Moon,
    'sun-moon': Lucide.SunMoon,
    'logout': Lucide.LogOut,
    'chevron-down': Lucide.ChevronDown,
    'chevron-left': Lucide.ChevronLeft,
    'chevron-right': Lucide.ChevronRight,
    'chevrons-down-up': Lucide.ChevronsDownUp,
    'filter': Lucide.Funnel,
    'settings': Lucide.Settings,
    'download': Lucide.Download,
    'upload': Lucide.Upload,
    'check': Lucide.Check,
    'check-circle': Lucide.CircleCheck,
    'alert-circle': Lucide.CircleAlert,
    'alert-triangle': Lucide.TriangleAlert,
    'triangle-alert': Lucide.TriangleAlert,
    'copy': Lucide.Copy,
    'refresh-cw': Lucide.RefreshCw,
    'more-vertical': Lucide.EllipsisVertical,
    'more-horizontal': Lucide.Ellipsis,
    'inbox': Lucide.Inbox,
    'file-text': Lucide.FileText,
    'bell': Lucide.Bell,
    'user-plus': Lucide.UserPlus,
    'shield-alert': Lucide.ShieldAlert,
    'type': Lucide.Type,
    'tag': Lucide.Tag,
    'tags': Lucide.Tags,
    'activity': Lucide.Activity,
    'database': Lucide.Database,
    'users-round': Lucide.UsersRound,
    'eye-off': Lucide.EyeOff,
    'undo-2': Lucide.Undo2,
    'redo-2': Lucide.Redo2,
    'rocket': Lucide.Rocket,
    'external-link': Lucide.ExternalLink,
    'box': Lucide.Box,
    'heading': Lucide.Heading,
    'image': Lucide.Image,
    'image-off': Lucide.ImageOff,
    'video': Lucide.Video,
    'film': Lucide.Film,
    'music': Lucide.Music,
    'link': Lucide.Link,
    'link-2': Lucide.Link2,
    'folder': Lucide.Folder,
    'folder-plus': Lucide.FolderPlus,
    'layout-grid': Lucide.LayoutGrid,
    'list': Lucide.List,
    'crosshair': Lucide.Crosshair,
    'sliders-horizontal': Lucide.SlidersHorizontal,
    'lock': Lucide.Lock,
    'sparkles': Lucide.Sparkles,
  };

	const Comp: Component<any> = $derived(map[name] ?? Lucide.Search);
</script>

<Comp {size} class={cls} />
