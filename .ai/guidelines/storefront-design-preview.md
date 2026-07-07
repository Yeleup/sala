# Storefront Design Preview

Any change to storefront UI or storefront-facing visual design MUST be reflected in:

resources/views/storefront-design-preview.blade.php

Do this even when the actual implementation also changes another Blade view, Livewire component, Vue/React component, or CSS/Tailwind classes.

This applies to product pages, category pages, product cards, storefront homepage sections, mobile layouts, responsive states, and visual mockups.

The preview update must be a close visual match to the production UI, not just a short mention or rough approximation of the new feature. Match the relevant Blade/component structure, layout, controls, labels, spacing, and states closely enough that the preview can be used for design review.

When a storefront page has both mobile and desktop preview states, update every relevant viewport/state shown in `storefront-design-preview.blade.php`. Do not update only mobile or only desktop unless the changed screen exists in the preview for only that viewport.
