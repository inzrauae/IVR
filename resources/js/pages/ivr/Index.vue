<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import PageHeader from '@/components/PageHeader.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { formatRelative } from '@/lib/format';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, useForm } from '@inertiajs/vue3';
import { AudioLines, LoaderCircle, Plus } from 'lucide-vue-next';
import { ref } from 'vue';

defineProps<{
    menus: {
        id: number;
        number: string;
        name: string;
        timeout: number;
        max_retries: number;
        options_count: number;
        has_greeting: boolean;
        published_at: string | null;
        updated_at: string;
    }[];
    nextNumber: string;
    can: { manage: boolean; publish: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'IVR Builder', href: '/ivr' }];

const creating = ref(false);
const form = useForm({ number: '', name: '' });
const open = (next: string) => {
    form.reset();
    form.number = next;
    form.clearErrors();
    creating.value = true;
};
</script>

<template>
    <Head title="IVR Builder" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <PageHeader
                title="IVR Builder"
                description="Voice menus: “Press 1 for sales, 2 for support…”. Point a DID at a menu to answer calls with it."
            >
                <Button v-if="can.manage" @click="open(nextNumber)"><Plus class="size-4" /> New menu</Button>
            </PageHeader>

            <div v-if="menus.length" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Link v-for="menu in menus" :key="menu.id" :href="route('ivr.edit', { ivr: menu.id })" class="group">
                    <Card class="h-full transition-colors group-hover:border-primary/50">
                        <CardHeader class="flex-row items-start justify-between space-y-0">
                            <div>
                                <CardTitle class="text-base">{{ menu.name }}</CardTitle>
                                <CardDescription>Dial {{ menu.number }}</CardDescription>
                            </div>
                            <StatusBadge :status="menu.published_at ? 'live' : 'draft'" />
                        </CardHeader>
                        <CardContent class="flex flex-wrap gap-x-4 gap-y-1 text-sm text-muted-foreground">
                            <span class="inline-flex items-center gap-1">
                                <AudioLines class="size-4" /> {{ menu.has_greeting ? 'Greeting uploaded' : 'No greeting' }}
                            </span>
                            <span>{{ menu.options_count }} option{{ menu.options_count === 1 ? '' : 's' }}</span>
                            <span>Edited {{ formatRelative(menu.updated_at) }}</span>
                        </CardContent>
                    </Card>
                </Link>
            </div>

            <Card v-else>
                <CardContent class="py-10 text-center text-sm text-muted-foreground">
                    No menus yet. <span v-if="can.manage">Create one, upload a greeting and choose what each key does.</span>
                </CardContent>
            </Card>
        </div>

        <Dialog :open="creating" @update:open="creating = $event">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>New IVR menu</DialogTitle>
                    <DialogDescription>It starts as a draft; callers hear it only after you publish it.</DialogDescription>
                </DialogHeader>
                <form id="ivr-create" class="grid gap-4" @submit.prevent="form.post(route('ivr.store'))">
                    <div class="grid gap-2">
                        <Label for="ivr-name">Name</Label>
                        <Input id="ivr-name" v-model="form.name" required maxlength="100" placeholder="e.g. Main menu" />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="ivr-number">Internal number</Label>
                        <Input id="ivr-number" v-model="form.number" required inputmode="numeric" />
                        <InputError :message="form.errors.number" />
                    </div>
                </form>
                <DialogFooter>
                    <Button variant="outline" @click="creating = false">Cancel</Button>
                    <Button type="submit" form="ivr-create" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="size-4 animate-spin" /> Create
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
