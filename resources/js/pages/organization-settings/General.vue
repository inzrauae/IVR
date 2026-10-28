<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import NativeSelect from '@/components/NativeSelect.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import OrganizationSettingsLayout from '@/layouts/OrganizationSettingsLayout.vue';
import { Head, router, useForm } from '@inertiajs/vue3';
import { ImageUp, LoaderCircle, Trash2 } from 'lucide-vue-next';
import { ref } from 'vue';

const props = defineProps<{
    section: string;
    canManage: boolean;
    organization: {
        name: string;
        timezone: string;
        locale: string;
        currency: string;
        date_format: string;
        contact_email: string | null;
        contact_phone: string | null;
        address: string | null;
        logo_url: string | null;
    };
    options: { timezones: string[]; currencies: string[]; languages: Record<string, string>; dateFormats: Record<string, string> };
}>();

const form = useForm({
    name: props.organization.name,
    timezone: props.organization.timezone,
    locale: props.organization.locale,
    currency: props.organization.currency,
    date_format: props.organization.date_format,
    contact_email: props.organization.contact_email ?? '',
    contact_phone: props.organization.contact_phone ?? '',
    address: props.organization.address ?? '',
});

const submit = () => form.put(route('organization-settings.general.update'), { preserveScroll: true, onSuccess: () => form.defaults() });

const fileInput = ref<HTMLInputElement | null>(null);
const logoForm = useForm<{ logo: File | null }>({ logo: null });
const uploadLogo = (event: Event) => {
    const file = (event.target as HTMLInputElement).files?.[0];
    if (!file) return;
    logoForm.logo = file;
    logoForm.post(route('organization-settings.logo.store'), {
        preserveScroll: true,
        forceFormData: true,
        onFinish: () => {
            logoForm.reset();
            if (fileInput.value) fileInput.value.value = '';
        },
    });
};
const removeLogo = () => router.delete(route('organization-settings.logo.destroy'), { preserveScroll: true });
</script>

<template>
    <Head title="General settings" />

    <OrganizationSettingsLayout
        :section="section"
        title="General"
        description="Your organization's identity, locale and contact details"
        :can-manage="canManage"
    >
        <div class="space-y-6">
            <Card>
                <CardContent class="flex flex-wrap items-center gap-5 p-5">
                    <div class="flex size-16 items-center justify-center overflow-hidden rounded-xl border bg-muted">
                        <img v-if="organization.logo_url" :src="organization.logo_url" alt="Organization logo" class="size-full object-contain" />
                        <ImageUp v-else class="size-6 text-muted-foreground" />
                    </div>
                    <div class="flex-1">
                        <p class="text-sm font-medium">Company logo</p>
                        <p class="text-xs text-muted-foreground">PNG, JPG or WEBP, up to 1 MB.</p>
                        <InputError :message="logoForm.errors.logo" />
                    </div>
                    <div v-if="canManage" class="flex gap-2">
                        <input ref="fileInput" type="file" accept="image/png,image/jpeg,image/webp" class="hidden" @change="uploadLogo" />
                        <Button variant="outline" size="sm" :disabled="logoForm.processing" @click="fileInput?.click()">
                            <LoaderCircle v-if="logoForm.processing" class="size-4 animate-spin" />
                            {{ organization.logo_url ? 'Replace' : 'Upload' }}
                        </Button>
                        <Button v-if="organization.logo_url" variant="ghost" size="icon" aria-label="Remove logo" @click="removeLogo">
                            <Trash2 class="size-4 text-destructive" />
                        </Button>
                    </div>
                </CardContent>
            </Card>

            <form class="space-y-6" @submit.prevent="submit">
                <fieldset :disabled="!canManage" class="grid gap-5 sm:grid-cols-2">
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="name">Organization name</Label>
                        <Input id="name" v-model="form.name" required />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="timezone">Time zone</Label>
                        <NativeSelect id="timezone" v-model="form.timezone">
                            <option v-for="tz in options.timezones" :key="tz" :value="tz">{{ tz }}</option>
                        </NativeSelect>
                        <InputError :message="form.errors.timezone" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="locale">Language</Label>
                        <NativeSelect id="locale" v-model="form.locale">
                            <option v-for="(label, value) in options.languages" :key="value" :value="value">{{ label }}</option>
                        </NativeSelect>
                        <InputError :message="form.errors.locale" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="date_format">Date format</Label>
                        <NativeSelect id="date_format" v-model="form.date_format">
                            <option v-for="(example, value) in options.dateFormats" :key="value" :value="value">{{ example }}</option>
                        </NativeSelect>
                        <InputError :message="form.errors.date_format" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="currency">Currency</Label>
                        <NativeSelect id="currency" v-model="form.currency">
                            <option v-for="c in options.currencies" :key="c" :value="c">{{ c }}</option>
                        </NativeSelect>
                        <InputError :message="form.errors.currency" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="contact_email">Contact email</Label>
                        <Input id="contact_email" v-model="form.contact_email" type="email" />
                        <InputError :message="form.errors.contact_email" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="contact_phone">Contact phone</Label>
                        <Input id="contact_phone" v-model="form.contact_phone" type="tel" />
                        <InputError :message="form.errors.contact_phone" />
                    </div>
                    <div class="grid gap-2 sm:col-span-2">
                        <Label for="address">Business address</Label>
                        <textarea
                            id="address"
                            v-model="form.address"
                            rows="3"
                            class="rounded-md border border-input bg-transparent px-3 py-2 text-sm shadow-sm focus-visible:outline-none focus-visible:ring-1 focus-visible:ring-ring"
                        />
                        <InputError :message="form.errors.address" />
                    </div>
                </fieldset>
                <Button v-if="canManage" type="submit" :disabled="form.processing || !form.isDirty">
                    <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                    Save changes
                </Button>
            </form>
        </div>
    </OrganizationSettingsLayout>
</template>
