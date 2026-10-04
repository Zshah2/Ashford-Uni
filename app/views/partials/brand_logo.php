<?php
/**
 * Theme-aware Ashford wordmark (light + dark SVGs).
 *
 * @var array|null $app
 * @var string|null $logoClass  Tailwind classes for both images (size/layout)
 * @var string|null $logoAlt
 * @var bool|null $logoLazy
 * @var string|null $logoVariantDark  'navy' (default) or 'gold'
 */
$logoAlt = isset($logoAlt) && is_string($logoAlt) && $logoAlt !== ''
    ? $logoAlt
    : (string)(($app['site']['name'] ?? 'Ashford College'));
$logoClass = isset($logoClass) && is_string($logoClass) && $logoClass !== ''
    ? $logoClass
    : 'h-10 w-auto max-w-[12rem] shrink-0 object-contain';
$logoLazy = !empty($logoLazy);
$darkFile = (isset($logoVariantDark) && $logoVariantDark === 'gold')
    ? 'ashford-logo-dark-gold.svg'
    : 'ashford-logo-dark.svg';
$loading = $logoLazy ? 'lazy' : 'eager';
$logoBase = '/assets/img/logo/';
?>
<span class="inline-flex items-center">
  <img
    src="<?= htmlspecialchars(url($logoBase . 'ashford-logo-light.svg')) ?>"
    alt="<?= htmlspecialchars($logoAlt) ?>"
    width="520"
    height="190"
    class="<?= htmlspecialchars($logoClass) ?> dark:hidden"
    loading="<?= htmlspecialchars($loading) ?>"
    decoding="async"
  />
  <img
    src="<?= htmlspecialchars(url($logoBase . $darkFile)) ?>"
    alt=""
    width="520"
    height="190"
    class="<?= htmlspecialchars($logoClass) ?> hidden dark:block"
    loading="<?= htmlspecialchars($loading) ?>"
    decoding="async"
    aria-hidden="true"
  />
</span>
