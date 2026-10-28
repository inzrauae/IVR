<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import ToggleField from '@/components/ToggleField.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { LoaderCircle, Pencil, Plus } from 'lucide-vue-next';
import { ref } from 'vue';

interface PackageRow {
    id: number;
    name: string;
    slug: string;
    description: string | null;
    currency: string;
    monthly_price: string;
    annual_price: string | null;
    max_users: number | null;
    max_extensions: number | null;
    max_concurrent_calls: number | null;
    recording_storage_mb: number | null;
    trial_days: number;
    overage_per_extension: string | null;
    is_active: boolean;
    features: string[];
    subscribers: number;
}

defineProps<{
    packages: PackageRow[];
    featureOptions: Record<string, string>;
}>();

const blank = {
    name: '',
    slug: '',
    description: '',
    currency: 'LKR',
    // Empty strings stand for "unlimited"/"not set" and are sent as null.
    monthly_price: '' as string | number,
    annual_price: '' as string | number,
    max_users: '' as string | number,
    max_extensions: '' as string | number,
    max_concurrent_calls: '' as string | number,
    recording_storage_mb: '' as string | number,
    trial_days: 14,
    overage_per_extension: '' as string | number,
    is_active: true,
    features: [] as string[],
};

const editing = ref<PackageRow | 'new' | null>(null);
const form = useForm({ ...blank });

const open = (pkg: PackageRow | 'new') => {
    form.clearErrors();
    if (pkg === 'new') {
        form.defaults({ ...blank, features: [] });
    } else {
        form.defaults({
            ...blank,
            name: pkg.name,
            slug: pkg.slug,
            description: pkg.description ?? '',
            currency: pkg.currency,
            monthly_price: pkg.monthly_price,
            annual_price: pkg.annual_price ?? '',
            max_users: pkg.max_users ?? '',
            max_extensions: pkg.max_extensions ?? '',
            max_concurrent_calls: pkg.max_concurrent_calls ?? '',
            recording_storage_mb: pkg.recording_storage_mb ?? '',
            trial_days: pkg.trial_days,
            overage_per_extension: pkg.overage_per_extension ?? '',
            is_active: pkg.is_active,
            features: [...pkg.features],
        });
    }
    form.reset();
    editing.value = pkg;
};

// Empty number inputs mean "unlimited" and are sent as null.
const nullIfEmpty = (v: unknown) => (v === '' || v === undefined ? null : v);

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    const payload = form.transform((data) => ({
        ...data,
        annual_price: nullIfEmpty(data.annual_price),
        max_users: nullIfEmpty(data.max_users),
        max_extensions: nullIfEmpty(data.max_extensions),
        max_concurrent_calls: nullIfEmpty(data.max_concurrent_calls),
        recording_storage_mb: nullIfEmpty(data.recording_storage_mb),
        overage_per_extension: nullIfEmpty(data.overage_per_extension),
    }));

    if (editing.value === 'new') {
        payload.post(route('platform.packages.store'), options);
    } else if (editing.value) {
        payload.put(route('platform.packages.update', { package: editing.value.id }), options);
    }
};

const toggleFeature = (key: string) => {
    form.features = form.features.includes(key) ? form.features.filter((f) => f !== key) : [...form.features, key];
};

const money = (amount: number | string, currency: string) =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency, maximumFractionDigits: 0 }).format(Number(amount));

const numberFields = [
    { key: 'max_users', label: 'Max users' },
    { key: 'max_extensions', label: 'Max extensions' },
    { key: 'max_concurrent_calls', label: 'Concurrent calls' },
    { key: 'recording_storage_mb', label: 'Recording storage (MB)' },
] as const;
</script>

<template>
    <Head title="Packages" />

    <AppLayout
        :breadcrumbs="[
            { title: 'Platform', href: '/platform' },
            { title: 'Packages', href: '/platform/packages' },
        ]"
    >
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight">Packages</h1>
                    <p class="text-sm text-muted-foreground">Plans, prices and limits offered to organizations</p>
                </div>
                <Button @click="open('new')"><Plus class="size-4" /> New package</Button>
            </div>

            <div class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Card v-for="p in packages" :key="p.id">
                    <CardHeader class="flex-row items-start justify-between space-y-0">
                        <div>
                            <CardTitle class="text-base">{{ p.name }}</CardTitle>
                            <CardDescription class="font-mono text-xs">{{ p.slug }}</CardDescription>
                        </div>
                        <StatusBadge :status="p.is_active ? 'active' : 'disabled'" />
                    </CardHeader>
                    <CardContent class="space-y-3 text-sm">
                        <p>
                            <span class="text-xl font-semibold tabular-nums">{{ money(p.monthly_price, p.currency) }}</span>
                            <span class="text-muted-foreground"> / month</span>
                            <span v-if="p.annual_price" class="block text-xs text-muted-foreground"
                                >{{ money(p.annual_price, p.currency) }} / year</span
                            >
                        </p>
                        <dl class="grid grid-cols-2 gap-y-1">
                            <dt class="text-muted-foreground">Users</dt>
                            <dd class="tabular-nums">{{ p.max_users ?? '∞' }}</dd>
                            <dt class="text-muted-foreground">Extensions</dt>
                            <dd class="tabular-nums">{{ p.max_extensions ?? '∞' }}</dd>
                            <dt class="text-muted-foreground">Concurrent calls</dt>
                            <dd class="tabular-nums">{{ p.max_concurrent_calls ?? '∞' }}</dd>
                            <dt class="text-muted-foreground">Trial</dt>
                            <dd>{{ p.trial_days }} days</dd>
                            <dt class="text-muted-foreground">Subscribers</dt>
                            <dd class="tabular-nums">{{ p.subscribers }}</dd>
                        </dl>
                        <Button variant="outline" size="sm" class="w-full" @click="open(p)"><Pencil class="size-4" /> Edit</Button>
                    </CardContent>
                </Card>
            </div>
        </div>

        <Dialog :open="editing !== null" @update:open="!$event && (editing = null)">
            <DialogContent class="max-h-[90vh] overflow-y-auto sm:max-w-2xl">
                <DialogHeader>
                    <DialogTitle>{{ editing === 'new' ? 'New package' : `Edit ${form.name}` }}</DialogTitle>
                    <DialogDescription
                        >Leave a limit empty for unlimited. Price changes apply to new subscriptions and plan changes.</DialogDescription
                    >
                </DialogHeader>
                <form id="package-form" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="pkg-name">Name</Label>
                        <Input id="pkg-name" v-model="form.name" required />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="pkg-slug">Slug</Label>
                        <Input id="pkg-slug" v-model="form.slug" required placeholder="business" />
                        <InputError :message="form.errors.slug" />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="pkg-description">Description</Label>
                        <Input id="pkg-description" v-model="form.description" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="pkg-monthly">Monthly price</Label>
                        <Input id="pkg-monthly" v-model="form.monthly_price" type="number" step="0.01" min="0" required />
                        <InputError :message="form.errors.monthly_price" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="pkg-annual">Annual price</Label>
                        <Input id="pkg-annual" v-model="form.annual_price" type="number" step="0.01" min="0" placeholder="12 × monthly" />
                        <InputError :message="form.errors.annual_price" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="pkg-currency">Currency</Label>
                        <Input id="pkg-currency" v-model="form.currency" maxlength="3" class="uppercase" required />
                        <InputError :message="form.errors.currency" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="pkg-trial">Trial days</Label>
                        <Input id="pkg-trial" v-model.number="form.trial_days" type="number" min="0" max="90" required />
                        <InputError :message="form.errors.trial_days" />
                    </div>
                    <div v-for="f in numberFields" :key="f.key" class="grid gap-2">
                        <Label :for="`pkg-${f.key}`">{{ f.label }}</Label>
                        <Input :id="`pkg-${f.key}`" v-model="form[f.key]" type="number" min="0" placeholder="Unlimited" />
                        <InputError :message="form.errors[f.key]" />
                    </div>
                    <fieldset class="sm:col-span-2">
                        <legend class="mb-2 text-sm font-medium">Included features</legend>
                        <div class="grid gap-2 sm:grid-cols-2">
                            <label v-for="(label, key) in featureOptions" :key="key" class="flex items-center gap-2 text-sm">
                                <input
                                    type="checkbox"
                                    class="size-4 accent-[hsl(var(--primary))]"
                                    :checked="form.features.includes(key)"
                                    @change="toggleFeature(key)"
                                />
                                {{ label }}
                            </label>
                        </div>
                    </fieldset>
                    <div class="sm:col-span-2">
                        <ToggleField v-model="form.is_active" label="Available for sign-up and plan changes" />
                    </div>
                </form>
                <DialogFooter>
                    <Button variant="outline" @click="editing = null">Cancel</Button>
                    <Button type="submit" form="package-form" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                        Save package
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
