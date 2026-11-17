<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import BuiltInFilters from '@/components/ryvr/BuiltInFilters.vue';
import ExclusionRules from '@/components/ryvr/ExclusionRules.vue';
import type { Rule } from '@/components/ryvr/ExclusionRules.vue';
import OrgNav from '@/components/ryvr/OrgNav.vue';

defineProps<{
    rules: Rule[];
    fields: {
        value: string;
        label: string;
        operators: { value: string; label: string }[];
    }[];
    canManage: boolean;
    clientIp: string;
}>();
</script>

<template>
    <Head title="Exclusions" />
    <OrgNav />

    <section class="settings-section">
        <div>
            <h2 class="font-medium">Rules for every site</h2>
            <p class="mt-1 text-sm text-muted-foreground">
                Exclude your office, your own monitoring, internal tools or test
                traffic everywhere at once. Sites can add their own rules in
                their settings.
            </p>
        </div>
        <div class="max-w-2xl">
            <ExclusionRules
                :rules="rules"
                :fields="fields"
                base-url="/organization/exclusions"
                :can-manage="canManage"
                :client-ip="clientIp"
            />
        </div>
    </section>

    <section class="settings-section">
        <div>
            <h2 class="font-medium">Always filtered</h2>
        </div>
        <div class="max-w-2xl">
            <BuiltInFilters />
        </div>
    </section>
</template>
