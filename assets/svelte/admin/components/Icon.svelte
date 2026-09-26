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
    'layers': Lucide.Layers,
    'puzzle': Lucide.Puzzle,
    'search': Lucide.Search,
    'history': Lucide.History,
    'edit': Lucide.Pencil,
    'pencil': Lucide.Pencil,
    'trash': Lucide.Trash2,
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
    'filter': Lucide.Filter,
    'settings': Lucide.Settings,
    'download': Lucide.Download,
    'upload': Lucide.Upload,
    'check': Lucide.Check,
    'check-circle': Lucide.CircleCheck,
    'alert-circle': Lucide.CircleAlert,
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
    'activity': Lucide.Activity,
    'database': Lucide.Database,
    'users-round': Lucide.UsersRound,
    'eye-off': Lucide.EyeOff,
  };

	const Comp: Component<any> = $derived(map[name] ?? Lucide.Search);
</script>

<Comp {size} class={cls} />
