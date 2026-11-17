<script setup lang="ts">
import { Head, Link, usePage } from '@inertiajs/vue3';
import { Button } from '@/components/ui/button';
import { login, register } from '@/routes';
import { index } from '@/routes/generations';

const page = usePage();
</script>

<template>
    <Head title="Pixelforge Studio" />
    <main
        class="flex min-h-screen items-center justify-center bg-background px-6 py-16 text-foreground"
    >
        <div class="max-w-xl space-y-7">
            <p
                class="text-sm font-medium tracking-widest text-muted-foreground uppercase"
            >
                From an idea to an image
            </p>
            <h1
                class="text-5xl leading-tight font-semibold tracking-tight sm:text-6xl"
            >
                Pixelforge Studio
            </h1>
            <p class="text-lg leading-relaxed text-muted-foreground">
                Generate images with clear estimates, live progress, and an
                exact record of every credit spent.
            </p>
            <div class="flex flex-wrap gap-3">
                <Button v-if="page.props.auth.user" as-child
                    ><Link :href="index()">Open the app</Link></Button
                >
                <template v-else
                    ><Button as-child
                        ><Link :href="register()">Register</Link></Button
                    ><Button variant="outline" as-child
                        ><Link :href="login()">Log in</Link></Button
                    ></template
                >
            </div>
        </div>
    </main>
</template>
