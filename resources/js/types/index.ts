import type { LucideIcon } from 'lucide-vue-next';

export interface Auth {
    user: User;
    role: string | null;
    is_platform_admin: boolean;
    permissions: string[];
}

export interface BreadcrumbItem {
    title: string;
    href: string;
}

export interface NavItem {
    title: string;
    href: string;
    icon?: LucideIcon;
    isActive?: boolean;
}

/** Sidebar entry as shared by the server (icon is a lucide component name). */
export interface ModuleNavItem {
    title: string;
    href: string;
    icon: string;
    available: boolean;
}

export interface ModuleNavGroup {
    group: string;
    items: ModuleNavItem[];
}

export interface Organization {
    id: number;
    name: string;
    slug: string;
    status: 'trial' | 'active' | 'suspended';
}

export interface SharedData {
    [key: string]: unknown;
    name: string;
    quote: { message: string; author: string };
    auth: Auth;
    organization: Organization | null;
    navigation: ModuleNavGroup[];
    flash: { success: string | null; error: string | null; token: string | null };
    ziggy: {
        location: string;
        url: string;
        port: null | number;
        defaults: Record<string, unknown>;
        routes: Record<string, string>;
    };
}

export interface User {
    id: number;
    name: string;
    email: string;
    avatar?: string;
    email_verified_at: string | null;
    created_at: string;
    updated_at: string;
}

export interface AuditEntry {
    id: number;
    action: string;
    created_at: string;
    user: { id: number; name: string } | null;
    organization?: { id: number; name: string } | null;
}

export type BreadcrumbItemType = BreadcrumbItem;
