<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Check, ExternalLink, LoaderCircle } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface PackageOption {
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
}

const props = defineProps<{
    subscription: {
        package: {
            id: number;
            name: string;
            max_users: number | null;
            max_extensions: number | null;
            max_concurrent_calls: number | null;
            recording_storage_mb: number | null;
        };
        features: string[];
        status: string;
        billing_cycle: string;
        price: string;
        currency: string;
        trial_ends_at: string | null;
        current_period_end: string | null;
    } | null;
    usage: { users: number };
    packages: PackageOption[];
    invoices: { id: number; number: string; status: string; total: string; currency: string; issued_at: string; due_at: string }[];
    payments: { id: number; invoice: string | null; amount: string; currency: string; method: string; reference: string | null; paid_at: string }[];
    billingEmail: string | null;
    seller: { bank_details: string | null };
    canManage: boolean;
}>();

const featureLabels: Record<string, string> = {
    'ivr.basic': 'Basic IVR',
    'calls.transfer': 'Call transfers',
    recordings: 'Call recording',
    'calls.live_monitoring': 'Live monitoring',
    'reports.manager': 'Manager reports',
    'dids.dedicated': 'Dedicated DID support',
};

const money = (amount: number | string, currency: string) =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency, maximumFractionDigits: 2 }).format(Number(amount));
const formatDate = (iso: string | null) => (iso ? new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' }) : '—');

const trialDaysLeft = computed(() =>
    props.subscription?.trial_ends_at
        ? Math.max(0, Math.ceil((new Date(props.subscription.trial_ends_at).getTime() - Date.now()) / 86_400_000))
        : null,
);
const openBalance = computed(() => props.invoices.filter((i) => i.status === 'open' || i.status === 'overdue'));

// Plan change
const cycle = ref<'monthly' | 'annual'>((props.subscription?.billing_cycle as 'monthly' | 'annual') ?? 'monthly');
const choosing = ref<PackageOption | null>(null);
const planForm = useForm({ package_id: 0, billing_cycle: 'monthly' });
const priceFor = (p: PackageOption) => (cycle.value === 'annual' ? (p.annual_price ?? Number(p.monthly_price) * 12) : p.monthly_price);
const isCurrent = (p: PackageOption) => props.subscription?.package.id === p.id && props.subscription?.billing_cycle === cycle.value;
const confirmPlan = () => {
    if (!choosing.value) return;
    planForm.package_id = choosing.value.id;
    planForm.billing_cycle = cycle.value;
    planForm.post(route('billing.plan'), { preserveScroll: true, onSuccess: () => (choosing.value = null) });
};

// Billing contact
const contactForm = useForm({ billing_email: props.billingEmail ?? '' });
const saveContact = () => contactForm.put(route('billing.contact'), { preserveScroll: true });
</script>

<template>
    <Head title="Billing" />

    <AppLayout :breadcrumbs="[{ title: 'Billing', href: '/billing' }]">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <div>
                <h1 class="text-xl font-semibold tracking-tight">Billing</h1>
                <p class="text-sm text-muted-foreground">Your plan, usage, invoices and payments</p>
            </div>

            <div v-if="openBalance.length" class="rounded-lg border border-amber-500/40 bg-amber-500/5 p-4 text-sm">
                <p class="font-medium">You have {{ openBalance.length }} unpaid invoice{{ openBalance.length > 1 ? 's' : '' }}.</p>
                <p v-if="seller.bank_details" class="mt-1 whitespace-pre-line text-muted-foreground">{{ seller.bank_details }}</p>
                <p class="mt-1 text-muted-foreground">Use the invoice number as your payment reference. Payments are confirmed by our team.</p>
            </div>

            <div class="grid gap-6 lg:grid-cols-3">
                <Card class="lg:col-span-2">
                    <CardHeader class="flex-row items-start justify-between space-y-0">
                        <div>
                            <CardDescription>Current plan</CardDescription>
                            <CardTitle class="mt-1 text-2xl">{{ subscription?.package.name ?? 'No active plan' }}</CardTitle>
                        </div>
                        <StatusBadge v-if="subscription" :status="subscription.status" />
                    </CardHeader>
                    <CardContent v-if="subscription" class="space-y-4">
                        <p class="text-sm">
                            <span class="text-2xl font-semibold tabular-nums">{{ money(subscription.price, subscription.currency) }}</span>
                            <span class="text-muted-foreground"> / {{ subscription.billing_cycle === 'annual' ? 'year' : 'month' }}</span>
                        </p>
                        <p class="text-sm text-muted-foreground">
                            <template v-if="trialDaysLeft !== null && subscription.status === 'trialing'">
                                Free trial · {{ trialDaysLeft }} days left. Your first invoice is issued on
                                {{ formatDate(subscription.trial_ends_at) }}.
                            </template>
                            <template v-else>Renews on {{ formatDate(subscription.current_period_end) }}.</template>
                        </p>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <div class="rounded-lg border p-3">
                                <p class="text-xs text-muted-foreground">Users</p>
                                <p class="mt-1 font-semibold tabular-nums">{{ usage.users }} / {{ subscription.package.max_users ?? '∞' }}</p>
                                <div v-if="subscription.package.max_users" class="mt-2 h-1.5 overflow-hidden rounded-full bg-muted">
                                    <div
                                        class="h-full rounded-full bg-primary"
                                        :style="{ width: Math.min(100, (usage.users / subscription.package.max_users) * 100) + '%' }"
                                    />
                                </div>
                            </div>
                            <div class="rounded-lg border p-3">
                                <p class="text-xs text-muted-foreground">Extensions</p>
                                <p class="mt-1 font-semibold tabular-nums">{{ subscription.package.max_extensions ?? '∞' }} included</p>
                            </div>
                            <div class="rounded-lg border p-3">
                                <p class="text-xs text-muted-foreground">Concurrent calls</p>
                                <p class="mt-1 font-semibold tabular-nums">{{ subscription.package.max_concurrent_calls ?? '∞' }}</p>
                            </div>
                        </div>
                        <ul class="grid gap-1.5 text-sm sm:grid-cols-2">
                            <li v-for="f in subscription.features" :key="f" class="flex items-center gap-2">
                                <Check class="size-4 text-emerald-600" /> {{ featureLabels[f] ?? f }}
                            </li>
                        </ul>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader>
                        <CardTitle class="text-base">Billing contact</CardTitle>
                        <CardDescription>Invoices are addressed to this email</CardDescription>
                    </CardHeader>
                    <CardContent>
                        <form class="space-y-3" @submit.prevent="saveContact">
                            <Input
                                v-model="contactForm.billing_email"
                                type="email"
                                placeholder="accounts@example.com"
                                :disabled="!canManage"
                                aria-label="Billing email"
                            />
                            <InputError :message="contactForm.errors.billing_email" />
                            <Button v-if="canManage" type="submit" variant="outline" size="sm" :disabled="contactForm.processing">Save</Button>
                        </form>
                    </CardContent>
                </Card>
            </div>

            <Card v-if="canManage">
                <CardHeader class="flex-row flex-wrap items-center justify-between gap-3 space-y-0">
                    <div>
                        <CardTitle class="text-base">Change plan</CardTitle>
                        <CardDescription>Changes take effect immediately</CardDescription>
                    </div>
                    <div class="inline-flex rounded-lg border p-0.5 text-sm" role="radiogroup" aria-label="Billing cycle">
                        <button
                            v-for="c in ['monthly', 'annual'] as const"
                            :key="c"
                            type="button"
                            role="radio"
                            :aria-checked="cycle === c"
                            class="rounded-md px-3 py-1 capitalize"
                            :class="cycle === c ? 'bg-primary text-primary-foreground' : 'text-muted-foreground'"
                            @click="cycle = c"
                        >
                            {{ c }}
                        </button>
                    </div>
                </CardHeader>
                <CardContent class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                    <div
                        v-for="p in packages"
                        :key="p.id"
                        class="flex flex-col rounded-xl border p-5"
                        :class="{ 'border-primary ring-1 ring-primary': isCurrent(p) }"
                    >
                        <p class="font-semibold">{{ p.name }}</p>
                        <p class="mt-1 min-h-10 text-sm text-muted-foreground">{{ p.description }}</p>
                        <p class="mt-3">
                            <span class="text-2xl font-semibold tabular-nums">{{ money(priceFor(p), p.currency) }}</span>
                            <span class="text-sm text-muted-foreground"> / {{ cycle === 'annual' ? 'year' : 'month' }}</span>
                        </p>
                        <ul class="mt-3 flex-1 space-y-1 text-sm text-muted-foreground">
                            <li>{{ p.max_users ?? 'Unlimited' }} users</li>
                            <li>{{ p.max_extensions ?? 'Unlimited' }} extensions</li>
                            <li>{{ p.max_concurrent_calls ?? 'Unlimited' }} concurrent calls</li>
                        </ul>
                        <Button class="mt-4" :variant="isCurrent(p) ? 'outline' : 'default'" :disabled="isCurrent(p)" @click="choosing = p">
                            {{ isCurrent(p) ? 'Current plan' : 'Switch to ' + p.name }}
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <div class="grid gap-6 xl:grid-cols-2">
                <Card>
                    <CardHeader><CardTitle class="text-base">Invoices</CardTitle></CardHeader>
                    <CardContent class="overflow-x-auto p-0">
                        <table v-if="invoices.length" class="w-full text-sm">
                            <thead class="border-y bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th class="px-4 py-2 font-medium">Number</th>
                                    <th class="px-4 py-2 font-medium">Issued</th>
                                    <th class="px-4 py-2 font-medium">Due</th>
                                    <th class="px-4 py-2 text-right font-medium">Total</th>
                                    <th class="px-4 py-2 font-medium">Status</th>
                                    <th class="px-4 py-2"><span class="sr-only">View</span></th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="i in invoices" :key="i.id">
                                    <td class="px-4 py-2 font-mono text-xs">{{ i.number }}</td>
                                    <td class="px-4 py-2">{{ formatDate(i.issued_at) }}</td>
                                    <td class="px-4 py-2">{{ formatDate(i.due_at) }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ money(i.total, i.currency) }}</td>
                                    <td class="px-4 py-2"><StatusBadge :status="i.status" /></td>
                                    <td class="px-4 py-2 text-right">
                                        <a
                                            :href="route('billing.invoices.show', { invoice: i.id })"
                                            target="_blank"
                                            class="inline-flex text-primary"
                                            :aria-label="`Open ${i.number}`"
                                        >
                                            <ExternalLink class="size-4" />
                                        </a>
                                    </td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="px-6 pb-6 text-sm text-muted-foreground">No invoices yet.</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader><CardTitle class="text-base">Payments</CardTitle></CardHeader>
                    <CardContent class="overflow-x-auto p-0">
                        <table v-if="payments.length" class="w-full text-sm">
                            <thead class="border-y bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                                <tr>
                                    <th class="px-4 py-2 font-medium">Date</th>
                                    <th class="px-4 py-2 font-medium">Invoice</th>
                                    <th class="px-4 py-2 font-medium">Method</th>
                                    <th class="px-4 py-2 text-right font-medium">Amount</th>
                                </tr>
                            </thead>
                            <tbody class="divide-y">
                                <tr v-for="p in payments" :key="p.id">
                                    <td class="px-4 py-2">{{ formatDate(p.paid_at) }}</td>
                                    <td class="px-4 py-2 font-mono text-xs">{{ p.invoice }}</td>
                                    <td class="px-4 py-2">{{ p.method }}</td>
                                    <td class="px-4 py-2 text-right tabular-nums">{{ money(p.amount, p.currency) }}</td>
                                </tr>
                            </tbody>
                        </table>
                        <p v-else class="px-6 pb-6 text-sm text-muted-foreground">No payments recorded yet.</p>
                    </CardContent>
                </Card>
            </div>
        </div>

        <Dialog :open="choosing !== null" @update:open="!$event && (choosing = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Switch to {{ choosing?.name }}?</DialogTitle>
                    <DialogDescription>
                        <template v-if="subscription?.status === 'trialing'">Your free trial continues on the new plan.</template>
                        <template v-else>
                            The change applies now and an invoice for {{ choosing ? money(priceFor(choosing), choosing.currency) : '' }} is issued for
                            a new {{ cycle === 'annual' ? 'year' : 'month' }}. Unused time on your current plan is not credited.
                        </template>
                    </DialogDescription>
                </DialogHeader>
                <InputError :message="planForm.errors.package_id" />
                <DialogFooter>
                    <Button variant="outline" @click="choosing = null">Cancel</Button>
                    <Button :disabled="planForm.processing" @click="confirmPlan">
                        <LoaderCircle v-if="planForm.processing" class="size-4 animate-spin" />
                        Confirm
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
