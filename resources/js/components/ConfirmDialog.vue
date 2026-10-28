<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { Dialog, DialogContent, DialogDescription, DialogFooter, DialogHeader, DialogTitle } from '@/components/ui/dialog';

defineProps<{ open: boolean; title: string; description?: string; confirmLabel?: string; destructive?: boolean }>();
const emit = defineEmits<{ confirm: []; cancel: [] }>();
</script>

<template>
    <Dialog :open="open" @update:open="!$event && emit('cancel')">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{ title }}</DialogTitle>
                <DialogDescription v-if="description">{{ description }}</DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="emit('cancel')">Cancel</Button>
                <Button :variant="destructive === false ? 'default' : 'destructive'" @click="emit('confirm')">{{ confirmLabel ?? 'Delete' }}</Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>
