<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
import { home, login, logout, register } from '@/routes';
import { accept, decline } from '@/routes/invitations';

defineProps<{
    organization: string;
    email: string;
    role: string;
    inviter: string;
    token: string;
    signedIn: boolean;
    mismatch: string | null;
}>();

defineOptions({ layout: { title: 'You are invited', description: '' } });

const form = useForm({});
</script>

<template>
    <Head title="Invitation" />
    <div class="space-y-5 text-sm">
        <p>
            <strong>{{ inviter }}</strong> invited you to
            <strong>{{ organization }}</strong> as
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
                @click="router.post(logout().url)"
            >
                Sign out
            </button>
        </template>
        <form
            v-else-if="signedIn"
            class="flex flex-col gap-3"
            @submit.prevent="form.submit(accept(token))"
        >
            <button
                type="submit"
                class="btn-primary w-full"
                :disabled="form.processing"
            >
                Accept invitation
            </button>
            <button
                type="button"
                class="btn-secondary w-full"
                :disabled="form.processing"
                @click="form.submit(decline(token))"
            >
                Decline
            </button>
        </form>
        <template v-else>
            <p class="text-muted-foreground">
                Sign in or create an account as {{ email }} to review and accept
                this invitation. You will return here before joining.
            </p>
            <Link :href="register()" class="btn-primary w-full"
                >Create an account</Link
            >
            <p class="text-center text-muted-foreground">
                Already have an account?
                <Link
                    :href="login()"
                    class="text-foreground underline underline-offset-4"
                    >Sign in</Link
                >
            </p>
        </template>
        <Link
            v-if="!signedIn || mismatch"
            :href="home()"
            class="btn-secondary w-full"
        >
            Go back
        </Link>
    </div>
</template>
