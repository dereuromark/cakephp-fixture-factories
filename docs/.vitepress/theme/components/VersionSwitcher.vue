<script setup lang="ts">
import { computed, ref, watch } from 'vue'
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

const options = computed(() => [
  {
    label: versioning.value.latestLinkText,
    path: versioning.value.latestPath,
  },
  {
    label: versioning.value.legacyLinkText,
    path: versioning.value.legacyPath,
  },
])

const selectedPath = computed(() => {
  return versioning.value.currentVersion === versioning.value.legacyLinkText
    ? versioning.value.legacyPath
    : versioning.value.latestPath
})

const selectedValue = ref(selectedPath.value)

watch(
  selectedPath,
  (value) => {
    selectedValue.value = value
  },
  { immediate: true },
)

function navigate(event: Event): void {
  const path = (event.target as HTMLSelectElement).value
  if (!path || path === selectedPath.value) {
    return
  }

  window.location.assign(path)
}
</script>

<template>
  <div :class="props.mobile ? 'version-switcher version-switcher-mobile' : 'version-switcher'">
    <label class="version-label" for="docs-version-switcher">Version</label>
    <div class="version-select-wrap">
      <select
        id="docs-version-switcher"
        class="version-select"
        v-model="selectedValue"
        @change="navigate"
      >
        <option v-for="option in options" :key="option.path" :value="option.path">
          {{ option.label }}
        </option>
      </select>
      <span class="version-chevron" aria-hidden="true">▾</span>
    </div>
  </div>
</template>

<style scoped>
.version-switcher {
  display: flex;
  align-items: center;
  gap: 0.5rem;
  margin-left: 1rem;
  font-size: 0.875rem;
  white-space: nowrap;
}

.version-switcher-mobile {
  margin: 0.75rem 0 0;
  padding-top: 0.75rem;
  border-top: 1px solid var(--vp-c-divider);
}

.version-label {
  color: var(--vp-c-text-2);
  font-size: 0.8125rem;
  letter-spacing: 0.02em;
  text-transform: uppercase;
}

.version-select-wrap {
  position: relative;
  display: inline-flex;
  align-items: center;
}

.version-select {
  min-width: 9.5rem;
  padding: 0.35rem 2rem 0.35rem 0.75rem;
  border: 1px solid var(--vp-c-divider);
  border-radius: 999px;
  background: var(--vp-c-bg-soft);
  color: var(--vp-c-text-1);
  font: inherit;
  font-weight: 600;
  line-height: 1.2;
  cursor: pointer;
  appearance: none;
}

.version-select:hover {
  border-color: var(--vp-c-brand-1);
}

.version-select:focus {
  outline: 2px solid var(--vp-c-brand-1);
  outline-offset: 2px;
}

.version-chevron {
  position: absolute;
  right: 0.8rem;
  color: var(--vp-c-text-3);
  pointer-events: none;
}

.version-switcher-mobile .version-select {
  min-width: 100%;
}
</style>
