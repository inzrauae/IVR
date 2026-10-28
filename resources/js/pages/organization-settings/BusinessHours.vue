<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OrganizationSettingsLayout from '@/layouts/OrganizationSettingsLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { CalendarOff, LoaderCircle, Plus, Repeat, Trash2 } from 'lucide-vue-next';

interface Day {
    day_of_week: number;
    day: string;
    is_open: boolean;
    opens_at: string;
    closes_at: string;
}

const props = defineProps<{
    section: string;
    canManage: boolean;
    hours: Day[];
    holidays: { id: number; name: string; date: string; recurring: boolean }[];
    timezone: string;
}>();

const form = useForm({ hours: props.hours.map((d) => ({ ...d })) });
const submit = () => form.put(route('organization-settings.business-hours.update'), { preserveScroll: true, onSuccess: () => form.defaults() });

const holidayForm = useForm({ name: '', date: '', recurring: false });
const addHoliday = () =>
    holidayForm.post(route('organization-settings.holidays.store'), {
        preserveScroll: true,
        onSuccess: () => holidayForm.reset(),
    });

const removeHoliday = (id: number) => router.delete(route('organization-settings.holidays.destroy', { holiday: id }), { preserveScroll: true });

const dayError = (index: number) => (form.errors as Record<string, string>)[`hours.${index}.closes_at`] ?? (form.errors as Record<string, string>)[`hours.${index}.opens_at`];

const formatDate = (iso: string, recurring: boolean) =>
    new Date(iso + 'T00:00:00').toLocaleDateString(undefined, recurring ? { day: 'numeric', month: 'long' } : { dateStyle: 'medium' });
</script>

<template>
    <Head title="Business hours" />

    <OrganizationSettingsLayout
        :section="section"
        title="Business hours"
        :description="`Opening hours and holidays, in ${timezone}. Used by time-based call routing.`"
        :can-manage="canManage"
    >
        <div class="space-y-6">
            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Weekly schedule</CardTitle>
                </CardHeader>
                <CardContent>
                    <form @submit.prevent="submit">
                        <fieldset :disabled="!canManage" class="divide-y">
                            <div v-for="(day, i) in form.hours" :key="day.day_of_week" class="flex flex-wrap items-center gap-x-4 gap-y-2 py-3">
                                <label class="flex w-36 items-center gap-2 text-sm font-medium">
                                    <input v-model="day.is_open" type="checkbox" class="size-4 rounded border-input accent-[hsl(var(--primary))]" />
                                    {{ day.day }}
                                </label>
                                <template v-if="day.is_open">
                                    <Input v-model="day.opens_at" type="time" class="w-32" :aria-label="`${day.day} opens`" />
                                    <span class="text-sm text-muted-foreground">to</span>
                                    <Input v-model="day.closes_at" type="time" class="w-32" :aria-label="`${day.day} closes`" />
                                </template>
                                <span v-else class="text-sm text-muted-foreground">Closed</span>
                                <InputError class="w-full" :message="dayError(i)" />
                            </div>
                        </fieldset>
                        <Button v-if="canManage" type="submit" class="mt-4" :disabled="form.processing || !form.isDirty">
                            <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                            Save schedule
                        </Button>
                    </form>
                </CardContent>
            </Card>

            <Card>
                <CardHeader>
                    <CardTitle class="text-base">Holidays</CardTitle>
                    <CardDescription>Days treated as closed regardless of the weekly schedule</CardDescription>
                </CardHeader>
                <CardContent class="space-y-4">
                    <form v-if="canManage" class="grid gap-3 sm:grid-cols-[1fr_auto_auto_auto] sm:items-end" @submit.prevent="addHoliday">
                        <div class="grid gap-1.5">
                            <Label for="holiday-name">Name</Label>
                            <Input id="holiday-name" v-model="holidayForm.name" placeholder="Sinhala & Tamil New Year" required />
                        </div>
                        <div class="grid gap-1.5">
                            <Label for="holiday-date">Date</Label>
                            <Input id="holiday-date" v-model="holidayForm.date" type="date" required />
                        </div>
                        <label class="flex h-9 items-center gap-2 text-sm">
                            <input v-model="holidayForm.recurring" type="checkbox" class="size-4 accent-[hsl(var(--primary))]" />
                            Every year
                        </label>
                        <Button type="submit" variant="outline" :disabled="holidayForm.processing"><Plus class="size-4" /> Add</Button>
                        <InputError class="sm:col-span-4" :message="holidayForm.errors.name || holidayForm.errors.date" />
                    </form>

                    <ul v-if="holidays.length" class="divide-y rounded-lg border">
                        <li v-for="h in holidays" :key="h.id" class="flex items-center justify-between gap-3 px-4 py-2.5 text-sm">
                            <span>
                                <span class="font-medium">{{ h.name }}</span>
                                <span class="text-muted-foreground"> · {{ formatDate(h.date, h.recurring) }}</span>
                                <Repeat v-if="h.recurring" class="ml-1 inline size-3.5 text-muted-foreground" aria-label="Every year" />
                            </span>
                            <Button v-if="canManage" variant="ghost" size="icon" :aria-label="`Remove ${h.name}`" @click="removeHoliday(h.id)">
                                <Trash2 class="size-4 text-destructive" />
                            </Button>
                        </li>
                    </ul>
                    <p v-else class="flex items-center gap-2 text-sm text-muted-foreground"><CalendarOff class="size-4" /> No holidays added.</p>
                </CardContent>
            </Card>
        </div>
    </OrganizationSettingsLayout>
</template>
