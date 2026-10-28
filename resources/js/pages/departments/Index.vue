<script setup lang="ts">
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardDescription, CardHeader, CardTitle } from '@/components/ui/card';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import AppLayout from '@/layouts/AppLayout.vue';
import { type BreadcrumbItem } from '@/types';
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { LoaderCircle, Pencil, Plus, Trash2 } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Member {
    id: number;
    number: string;
    name: string;
    registered: boolean;
    status: string;
}

interface DepartmentRow {
    id: number;
    name: string;
    description: string | null;
    extensions_count: number;
    extensions: Member[];
}

const props = defineProps<{
    departments: DepartmentRow[];
    extensions: { id: number; number: string; name: string; department_id: number | null }[];
    can: { manage: boolean };
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Departments', href: '/departments' }];

// Create / edit share one dialog.
const editing = ref<DepartmentRow | 'new' | null>(null);
const form = useForm({ name: '', description: '', extension_ids: [] as number[] });

const open = (department: DepartmentRow | 'new') => {
    form.clearErrors();
    if (department === 'new') {
        form.name = '';
        form.description = '';
        form.extension_ids = [];
    } else {
        form.name = department.name;
        form.description = department.description ?? '';
        form.extension_ids = department.extensions.map((e) => e.id);
    }
    editing.value = department;
};

const departmentName = (id: number | null) => props.departments.find((d) => d.id === id)?.name;

// Extensions in another department are listed with a note, since saving moves them here.
const choices = computed(() =>
    props.extensions.map((e) => ({
        ...e,
        elsewhere:
            e.department_id !== null && (editing.value === 'new' || e.department_id !== editing.value?.id) ? departmentName(e.department_id) : null,
    })),
);

const submit = () => {
    const options = { preserveScroll: true, onSuccess: () => (editing.value = null) };
    if (editing.value === 'new') {
        form.post(route('departments.store'), options);
    } else if (editing.value) {
        form.put(route('departments.update', { department: editing.value.id }), options);
    }
};

const removing = ref<DepartmentRow | null>(null);
const confirmRemove = () => {
    if (!removing.value) return;
    router.delete(route('departments.destroy', { department: removing.value.id }), { preserveScroll: true, onFinish: () => (removing.value = null) });
};
</script>

<template>
    <Head title="Departments" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex flex-1 flex-col gap-6 p-4 md:p-6">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <h1 class="text-xl font-semibold tracking-tight">Departments</h1>
                    <p class="text-sm text-muted-foreground">Group extensions by team for routing and reporting.</p>
                </div>
                <Button v-if="can.manage" @click="open('new')">
                    <Plus class="size-4" />
                    New department
                </Button>
            </div>

            <div v-if="departments.length" class="grid gap-4 md:grid-cols-2 xl:grid-cols-3">
                <Card v-for="department in departments" :key="department.id">
                    <CardHeader class="flex-row items-start justify-between space-y-0">
                        <div>
                            <CardTitle class="text-base">{{ department.name }}</CardTitle>
                            <CardDescription>{{
                                department.description || `${department.extensions_count} extension${department.extensions_count === 1 ? '' : 's'}`
                            }}</CardDescription>
                        </div>
                        <div v-if="can.manage" class="flex">
                            <Button variant="ghost" size="icon" :aria-label="`Edit ${department.name}`" @click="open(department)">
                                <Pencil class="size-4" />
                            </Button>
                            <Button variant="ghost" size="icon" :aria-label="`Delete ${department.name}`" @click="removing = department">
                                <Trash2 class="size-4 text-destructive" />
                            </Button>
                        </div>
                    </CardHeader>
                    <CardContent>
                        <ul v-if="department.extensions.length" class="divide-y text-sm">
                            <li v-for="ext in department.extensions" :key="ext.id" class="flex items-center justify-between py-2">
                                <Link :href="route('extensions.show', { extension: ext.id })" class="hover:underline">
                                    <span class="font-mono font-semibold">{{ ext.number }}</span>
                                    {{ ext.name }}
                                </Link>
                                <span
                                    class="size-2 rounded-full"
                                    :class="ext.registered ? 'bg-emerald-500' : 'bg-muted-foreground/30'"
                                    :title="ext.registered ? 'Registered' : 'Not registered'"
                                />
                            </li>
                        </ul>
                        <p v-else class="text-sm text-muted-foreground">No extensions in this department.</p>
                    </CardContent>
                </Card>
            </div>

            <Card v-else>
                <CardContent class="py-10 text-center text-sm text-muted-foreground">
                    No departments yet.
                    <span v-if="can.manage">Create one such as Sales or Support, then add extensions to it.</span>
                </CardContent>
            </Card>
        </div>

        <Dialog :open="editing !== null" @update:open="!$event && (editing = null)">
            <DialogContent class="max-h-[90vh] overflow-y-auto">
                <DialogHeader>
                    <DialogTitle>{{ editing === 'new' ? 'New department' : `Edit ${form.name}` }}</DialogTitle>
                    <DialogDescription>An extension belongs to one department at a time.</DialogDescription>
                </DialogHeader>
                <form id="department-form" class="grid gap-4" @submit.prevent="submit">
                    <div class="grid gap-2">
                        <Label for="dept-name">Name</Label>
                        <Input id="dept-name" v-model="form.name" required maxlength="100" autocomplete="off" />
                        <InputError :message="form.errors.name" />
                    </div>
                    <div class="grid gap-2">
                        <Label for="dept-description">Description</Label>
                        <Input id="dept-description" v-model="form.description" maxlength="255" autocomplete="off" />
                        <InputError :message="form.errors.description" />
                    </div>
                    <fieldset class="grid gap-2">
                        <legend class="mb-2 text-sm font-medium">Extensions</legend>
                        <p v-if="!extensions.length" class="text-sm text-muted-foreground">Create extensions first.</p>
                        <div class="max-h-64 space-y-1 overflow-y-auto rounded-md border p-2">
                            <label
                                v-for="ext in choices"
                                :key="ext.id"
                                class="flex cursor-pointer items-center gap-3 rounded px-2 py-1.5 text-sm hover:bg-muted/50"
                            >
                                <input v-model="form.extension_ids" type="checkbox" :value="ext.id" class="size-4 rounded border-input" />
                                <span class="font-mono font-semibold">{{ ext.number }}</span>
                                <span class="flex-1">{{ ext.name }}</span>
                                <span v-if="ext.elsewhere" class="text-xs text-muted-foreground">in {{ ext.elsewhere }}</span>
                            </label>
                        </div>
                        <InputError :message="form.errors.extension_ids" />
                    </fieldset>
                </form>
                <DialogFooter>
                    <Button variant="outline" @click="editing = null">Cancel</Button>
                    <Button type="submit" form="department-form" :disabled="form.processing">
                        <LoaderCircle v-if="form.processing" class="size-4 animate-spin" />
                        Save
                    </Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>

        <Dialog :open="removing !== null" @update:open="!$event && (removing = null)">
            <DialogContent>
                <DialogHeader>
                    <DialogTitle>Delete {{ removing?.name }}?</DialogTitle>
                    <DialogDescription>Its extensions are kept and become unassigned.</DialogDescription>
                </DialogHeader>
                <DialogFooter>
                    <Button variant="outline" @click="removing = null">Cancel</Button>
                    <Button variant="destructive" @click="confirmRemove">Delete department</Button>
                </DialogFooter>
            </DialogContent>
        </Dialog>
    </AppLayout>
</template>
