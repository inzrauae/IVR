<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import ToggleField from '@/components/ToggleField.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OrganizationSettingsLayout from '@/layouts/OrganizationSettingsLayout.vue';
import { Head, useForm } from '@inertiajs/vue3';
import { Info, LoaderCircle } from 'lucide-vue-next';

const props = defineProps<{
    section: string;
    canManage: boolean;
    settings: {
        default_caller_id: string | null;
        ring_timeout: number;
        max_call_minutes: number;
        recording_mode: string;
        recording_announcement: boolean;
        allow_international: boolean;
        block_premium: boolean;
        hold_music: string;
    };
    recordingModes: Record<string, string>;
}>();

const form = useForm({ ...props.settings, default_caller_id: props.settings.default_caller_id ?? '' });
const submit = () => form.put(route('organization-settings.calls.update'), { preserveScroll: true, onSuccess: () => form.defaults() });
</script>

<template>
    <Head title="Call settings" />

    <OrganizationSettingsLayout :section="section" title="Call settings" description="Defaults for caller ID, ringing, recording and dialing permissions" :can-manage="canManage">
        <p class="mb-6 flex gap-2 rounded-lg border border-primary/20 bg-primary/5 px-4 py-3 text-sm">
            <Info class="mt-0.5 size-4 shrink-0 text-primary" />
            These values are saved now and applied to your phone system as extensions, routes and recordings are provisioned (Phases 9–12).
        </p>

        <form class="space-y-6" @submit.prevent="submit">
            <fieldset :disabled="!canManage" class="space-y-6">
                <div class="grid gap-5 sm:grid-cols-3">
                    <div class="grid gap-2">
                        <Label for="caller_id">Default caller ID</Label>
                        <Input id="caller_id" v-model="form.default_caller_id" placeholder="+94112345678" />
                        <InputError :message="form.errors.default_caller_id" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="ring_timeout">Ring timeout (seconds)</Label>
                        <Input id="ring_timeout" v-model.number="form.ring_timeout" type="number" min="5" max="120" />
                        <InputError :message="form.errors.ring_timeout" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="max_call_minutes">Max call length (minutes)</Label>
                        <Input id="max_call_minutes" v-model.number="form.max_call_minutes" type="number" min="1" max="1440" />
                        <InputError :message="form.errors.max_call_minutes" />
                    </div>
                </div>

                <div class="grid gap-5 sm:grid-cols-2">
                    <div class="grid gap-2">
                        <Label for="recording_mode">Record calls</Label>
                        <NativeSelect id="recording_mode" v-model="form.recording_mode">
                            <option v-for="(label, value) in recordingModes" :key="value" :value="value">{{ label }}</option>
                        </NativeSelect>
                        <InputError :message="form.errors.recording_mode" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="hold_music">Hold music</Label>
                        <NativeSelect id="hold_music" v-model="form.hold_music">
                            <option value="default">Default music on hold</option>
                            <option value="none">Silence</option>
                        </NativeSelect>
                    </div>
                </div>

                <div class="space-y-3">
                    <ToggleField
                        v-model="form.recording_announcement"
                        label="Play recording consent announcement"
                        description="Tells callers the call may be recorded. Required in many jurisdictions."
                        :disabled="!canManage"
                    />
                    <ToggleField
                        v-model="form.allow_international"
                        label="Allow international dialing"
                        description="Off by default to reduce toll-fraud risk."
                        :disabled="!canManage"
                    />
                    <ToggleField
                        v-model="form.block_premium"
                        label="Block premium-rate numbers"
                        description="Prevents calls to high-cost destinations."
                        :disabled="!canManage"
                    />
                </div>
            </fieldset>

            <Button v-if="canManage" type="submit" :disabled="form.processing || !form.isDirty">
                <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                Save changes
            </Button>
        </form>
    </OrganizationSettingsLayout>
</template>
