<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import StatCard from '@/components/StatCard.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { AlertTriangle, Banknote, LoaderCircle, Play, Wallet } from 'lucide-vue-next';
import { ref } from 'vue';

interface InvoiceRow {
    id: number;
    number: string;
    organization: string | null;
    status: string;
    total: string;
    balance: number;
    currency: string;
    issued_at: string;
    due_at: string;
}

defineProps<{
    invoices: { data: InvoiceRow[]; links: { url: string | null; label: string; active: boolean }[] };
    totals: { outstanding: number; overdue_count: number; collected_this_month: number };
    methods: Record<string, string>;
    filters: { status: string };
}>();

const tabs = [
    { key: 'open', label: 'Open' },
    { key: 'overdue', label: 'Overdue' },
    { key: 'paid', label: 'Paid' },
    { key: 'void', label: 'Void' },
    { key: 'all', label: 'All' },
];

const money = (amount: number | string, currency = 'LKR') =>
    new Intl.NumberFormat(undefined, { style: 'currency', currency, maximumFractionDigits: 2 }).format(Number(amount));
const formatDate = (iso: string) => new Date(iso).toLocaleDateString(undefined, { dateStyle: 'medium' });

const paying = ref<InvoiceRow | null>(null);
const payForm = useForm({ amount: '', method: 'bank_transfer', reference: '', paid_at: '' });
const openPayment = (invoice: InvoiceRow) => {
    paying.value = invoice;
    payForm.reset();
    payForm.clearErrors();
    payForm.amount = String(invoice.balance);
    payForm.paid_at = new Date().toISOString().slice(0, 10);
};
const submitPayment = () => {
    if (!paying.value) return;
    payForm.post(route('platform.invoices.payments.store', { invoice: paying.value.id }), {
        preserveScroll: true,
        onSuccess: () => (paying.value = null),
    });
};

const voiding = ref<InvoiceRow | null>(null);
const confirmVoid = () => {
    if (!voiding.value) return;
    router.post(route('platform.invoices.void', { invoice: voiding.value.id }), {}, { preserveScroll: true, onFinish: () => (voiding.value = null) });
};

const running = ref(false);
const runBilling = () =>
    router.post(
        route('platform.billing.run'),
        {},
        { preserveScroll: true, onStart: () => (running.value = true), onFinish: () => (running.value = false) },
    );
</script>

<template>
    <Head title="Invoices" />

    <AppLayout
        :breadcrumbs="[
            { title: 'Platform', href: '/platform' },
            { title: 'Invoices', href: '/platform/invoices' },
        ]"
    >
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight">Invoices</h1>
                    <p class="text-sm text-muted-foreground">Billing runs daily at 01:00. Payments are recorded here when received.</p>
                </div>
                <Button variant="outline" :disabled="running" @click="runBilling">
                    <LoaderCircle v-if="running" class="size-4 animate-spin" />
                    <Play v-else class="size-4" />
                    Run billing now
                </Button>
            </div>

            <div class="grid gap-4 sm:grid-cols-3">
                <StatCard label="Outstanding" :value="money(totals.outstanding)" :icon="Wallet" hint="Unpaid balance on open invoices" />
                <StatCard label="Overdue invoices" :value="totals.overdue_count" :icon="AlertTriangle" hint="Past their due date" />
                <StatCard label="Collected this month" :value="money(totals.collected_this_month)" :icon="Banknote" />
            </div>

            <nav class="flex flex-wrap gap-1" aria-label="Invoice status">
                <Link
                    v-for="t in tabs"
                    :key="t.key"
                    :href="route('platform.invoices.index', { status: t.key })"
                    preserve-scroll
                    class="rounded-md px-3 py-1.5 text-sm"
                    :class="filters.status === t.key ? 'bg-primary text-primary-foreground' : 'text-muted-foreground hover:bg-muted'"
                >
                    {{ t.label }}
                </Link>
            </nav>

            <Card>
                <CardContent class="overflow-x-auto p-0">
                    <table class="w-full text-sm">
                        <thead class="border-b bg-muted/40 text-left text-xs uppercase tracking-wide text-muted-foreground">
                            <tr>
                                <th class="px-4 py-3 font-medium">Invoice</th>
                                <th class="px-4 py-3 font-medium">Organization</th>
                                <th class="px-4 py-3 font-medium">Due</th>
                                <th class="px-4 py-3 text-right font-medium">Total</th>
                                <th class="px-4 py-3 text-right font-medium">Balance</th>
                                <th class="px-4 py-3 font-medium">Status</th>
                                <th class="px-4 py-3"><span class="sr-only">Actions</span></th>
                            </tr>
                        </thead>
                        <tbody class="divide-y">
                            <tr v-for="i in invoices.data" :key="i.id">
                                <td class="px-4 py-3">
                                    <div class="font-mono text-xs">{{ i.number }}</div>
                                    <div class="text-xs text-muted-foreground">Issued {{ formatDate(i.issued_at) }}</div>
                                </td>
                                <td class="px-4 py-3">{{ i.organization ?? '—' }}</td>
                                <td class="px-4 py-3">{{ formatDate(i.due_at) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ money(i.total, i.currency) }}</td>
                                <td class="px-4 py-3 text-right tabular-nums">{{ i.balance ? money(i.balance, i.currency) : '—' }}</td>
                                <td class="px-4 py-3"><StatusBadge :status="i.status" /></td>
                                <td class="whitespace-nowrap px-4 py-3 text-right">
                                    <template v-if="i.status === 'open' || i.status === 'overdue'">
                                        <Button size="sm" @click="openPayment(i)">Record payment</Button>
                                        <Button
                                            v-if="Number(i.balance) === Number(i.total)"
                                            size="sm"
                                            variant="ghost"
                                            class="text-destructive"
                                            @click="voiding = i"
                                        >
                                            Void
                                        </Button>
                                    </template>
                                </td>
                            </tr>
                            <tr v-if="!invoices.data.length">
                                <td colspan="7" class="px-4 py-10 text-center text-muted-foreground">No invoices in this view.</td>
                            </tr>
                        </tbody>
                    </table>
                </CardContent>
            </Card>

            <nav v-if="invoices.links.length > 3" class="flex flex-wrap gap-1" aria-label="Pagination">
                <template v-for="link in invoices.links" :key="link.label">
                    <Link
                        v-if="link.url"
                        :href="link.url"
                        preserve-scroll
                        class="rounded-md px-3 py-1.5 text-sm"
                        :class="link.active ? 'bg-primary text-primary-foreground' : 'hover:bg-muted'"
                    >
                        <span v-html="link.label" />
                    </Link>
                    <span v-else class="px-3 py-1.5 text-sm text-muted-foreground" v-html="link.label" />
                </template>
            </nav>
        </div>

        <Dialog :open="paying !== null" @update:open="!$event && (paying = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Record payment · {{ paying?.number }}</DialogTitle>
                    <DialogDescription>
                        {{ paying?.organization }} · balance {{ paying ? money(paying.balance, paying.currency) : '' }}. A full payment closes the
                        invoice and lifts any billing suspension.
                    </DialogDescription>
                </DialogHeader>
                <form id="record-payment" class="grid gap-4 sm:grid-cols-2" @submit.prevent="submitPayment">
                    <div class="grid gap-2">
                        <Label for="amount">Amount</Label>
                        <Input id="amount" v-model="payForm.amount" type="number" step="0.01" min="0.01" required />
                        <InputError :message="payForm.errors.amount" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="paid_at">Date received</Label>
                        <Input id="paid_at" v-model="payForm.paid_at" type="date" />
                        <InputError :message="payForm.errors.paid_at" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="method">Method</Label>
                        <NativeSelect id="method" v-model="payForm.method">
                            <option v-for="(label, value) in methods" :key="value" :value="value">{{ label }}</option>
                        </NativeSelect>
                    </div>
                    <div class="grid gap-2">
                        <Label for="reference">Reference</Label>
                        <Input id="reference" v-model="payForm.reference" placeholder="Bank ref / receipt no." />
                        <InputError :message="payForm.errors.reference" />
                    </div>
                </form>
                <DialogFooter>
                    <Button variant="outline" @click="paying = null">Cancel</Button>
                    <Button type="submit" form="record-payment" :disabled="payForm.processing">
                        <LoaderCircle v-if="payForm.processing" class="size-4 animate-spin" />
                        Record payment
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog :open="voiding !== null" @update:open="!$event && (voiding = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Void {{ voiding?.number }}?</DialogTitle>
                    <DialogDescription>The invoice is cancelled and no longer owed. This cannot be undone.</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="voiding = null">Cancel</Button>
                    <Button variant="destructive" @click="confirmVoid">Void invoice</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
