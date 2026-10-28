<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Head, Link } from '@inertiajs/vue3';
import { ShieldAlert } from 'lucide-vue-next';

defineProps<{ reason: string | null; canViewBilling?: boolean }>();
</script>

<template>
    <Head title="Account suspended" />

    <div class="flex min-h-svh items-center justify-center bg-background p-6">
        <div class="max-w-md text-center">
            <div class="mx-auto flex size-14 items-center justify-center rounded-2xl bg-destructive/10 text-destructive">
                <ShieldAlert class="size-7" />
            </div>
            <h1 class="mt-5 text-xl font-semibold tracking-tight">Access suspended</h1>
            <p class="mt-2 text-sm text-muted-foreground">
                {{ reason || 'This organization has been suspended.' }}
            </p>
            <p class="mt-2 text-sm text-muted-foreground">Contact your administrator or the platform provider to restore access.</p>
            <div class="mt-6 flex justify-center gap-2">
                <Button v-if="canViewBilling" as-child>
                    <Link :href="route('billing.index')">View invoices</Link>
                </Button>
                <Button as-child variant="outline">
                    <Link :href="route('logout')" method="post" as="button">Log out</Link>
                </Button>
            </div>
        </div>
    </div>
</template>
