<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';

defineProps<{
    organization: string;
    email: string;
    role: string;
    mismatch?: string;
}>();

defineOptions({ layout: { title: 'You are invited', description: '' } });
</script>

<template>
    <Head title="Invitation" />
    <div class="space-y-5 text-sm">
        <p>
            You have been invited to <strong>{{ organization }}</strong> as
            {{ role.toLowerCase() === 'admin' ? 'an' : 'a' }}
            {{ role.toLowerCase() }}. The invitation was sent to
            <strong>{{ email }}</strong
            >.
        </p>
        <template v-if="mismatch">
            <p class="text-muted-foreground">
                You are signed in as {{ mismatch }}. Sign out, then open the
                invitation link again and sign in as {{ email }}.
            </p>
            <button
                type="button"
                class="btn-primary w-full"
                @click="router.post('/logout')"
            >
                Sign out
            </button>
        </template>
        <template v-else>
            <Link href="/register" class="btn-primary w-full"
                >Create an account</Link
            >
            <p class="text-center text-muted-foreground">
                Already have an account?
                <Link
                    href="/login"
                    class="text-foreground underline underline-offset-4"
                    >Sign in</Link
                >
                and open the link again.
            </p>
        </template>
    </div>
</template>
