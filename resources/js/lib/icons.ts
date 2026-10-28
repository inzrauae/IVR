import {
    BellRing,
    Building2,
    Cable,
    ChartColumn,
    Contact,
    CreditCard,
    Disc3,
    Gauge,
    Hash,
    Headset,
    History,
    LayoutDashboard,
    ListOrdered,
    Package,
    Phone,
    PhoneCall,
    Plug,
    Receipt,
    Server,
    Settings,
    ShieldCheck,
    Split,
    Users,
    Voicemail,
    Workflow,
    type LucideIcon,
} from 'lucide-vue-next';

/** Icons the server may reference by name in navigation and module metadata. */
const icons: Record<string, LucideIcon> = {
    BellRing,
    Building2,
    Cable,
    ChartColumn,
    Contact,
    CreditCard,
    Disc3,
    Gauge,
    Hash,
    Headset,
    History,
    LayoutDashboard,
    ListOrdered,
    Package,
    Phone,
    PhoneCall,
    Plug,
    Receipt,
    Server,
    Settings,
    ShieldCheck,
    Split,
    Users,
    Voicemail,
    Workflow,
};

export function resolveIcon(name: string): LucideIcon {
    return icons[name] ?? LayoutDashboard;
}

/** "user.role_changed" -> "User role changed" */
export function humanizeAction(action: string): string {
    const text = action.replace(/[._]/g, ' ');
    return text.charAt(0).toUpperCase() + text.slice(1);
}
