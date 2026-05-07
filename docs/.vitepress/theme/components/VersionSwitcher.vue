<script setup lang="ts">
import { computed } from 'vue'
import { useData } from 'vitepress'

const props = defineProps<{
  mobile?: boolean
}>()

const { theme } = useData()

const versioning = computed(() => {
  const data = (theme.value as Record<string, unknown>).versioning as Record<string, string> | undefined

  return {
    currentVersion: data?.currentVersion ?? 'v2 (latest)',
    latestLinkText: data?.latestLinkText ?? 'v2 (latest)',
    legacyLinkText: data?.legacyLinkText ?? 'v1.x (legacy)',
    latestPath: data?.latestPath ?? '/cakephp-fixture-factories/',
    legacyPath: data?.legacyPath ?? '/cakephp-fixture-factories/1.x/',
  }
})

function navigate(path: string): void {
  window.location.assign(path)
}
</script>

<template>
  <div :class="props.mobile ? 'version-switcher version-switcher-mobile' : 'version-switcher'">
    <span class="version-current">{{ versioning.currentVersion }}</span>
    <span class="version-separator">/</span>
    <button class="version-link" type="button" @click="navigate(versioning.latestPath)">
      {{ versioning.latestLinkText }}
    </button>
    <span class="version-separator">/</span>
    <button class="version-link" type="button" @click="navigate(versioning.legacyPath)">
      {{ versioning.legacyLinkText }}
    </button>
  </div>
</template>

<style scoped>
.version-switcher {
  display: flex;
  align-items: center;
  gap: 0.45rem;
  margin-left: 1rem;
  font-size: 0.875rem;
  white-space: nowrap;
}

.version-switcher-mobile {
  margin: 0.75rem 0 0;
  padding-top: 0.75rem;
  border-top: 1px solid var(--vp-c-divider);
}

.version-current {
  color: var(--vp-c-text-1);
  font-weight: 600;
}

.version-link {
  border: 0;
  padding: 0;
  background: transparent;
  font: inherit;
  cursor: pointer;
  color: var(--vp-c-text-2);
  text-decoration: none;
}

.version-link:hover {
  color: var(--vp-c-brand-1);
}

.version-separator {
  color: var(--vp-c-text-3);
}
</style>
