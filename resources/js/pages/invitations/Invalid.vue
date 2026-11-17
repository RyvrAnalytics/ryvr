<script setup lang="ts">
import { Head, Link } from '@inertiajs/vue3';

defineOptions({
    layout: { title: 'Invitation not available', description: '' },
});
</script>

<template>
    <Head title="Invitation" />
    <div class="space-y-4 text-sm">
        <p>
            This invitation link has expired, was already used, or was revoked.
        </p>
        <p class="text-muted-foreground">
            Ask the person who invited you to send a new one.
        </p>
        <Link href="/login" class="text-foreground underline underline-offset-4"
            >Go to sign in</Link
        >
    </div>
</template>
