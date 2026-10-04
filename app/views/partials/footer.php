<?php
/** @var array $app */
?>
<footer id="contact" class="border-t border-slate-200 bg-slate-100 dark:border-fuchsia-500/25 dark:bg-[#060914]">
  <div class="mx-auto max-w-6xl px-4 py-10 sm:px-6">
    <div class="grid gap-10 md:grid-cols-12">
      <div class="md:col-span-5">
        <div class="flex items-center gap-3">
          <?php
          $logoClass = 'h-12 w-auto max-w-[14rem] shrink-0 object-contain sm:h-14 sm:max-w-[16rem]';
          $logoAlt = $app['site']['name'];
          $logoLazy = true;
          require view_path('partials/brand_logo.php');
          unset($logoLazy);
          ?>
          <div class="leading-tight">
            <div class="text-xs text-slate-400"><?= htmlspecialchars($app['site']['tagline']) ?></div>
          </div>
        </div>

        <p class="mt-4 max-w-md text-sm text-slate-600 dark:text-slate-300">
          Discover programs, visit campus, and connect with admissions. Explore opportunities built for your goals.
        </p>

        <div class="mt-6 space-y-2 text-sm text-slate-600 dark:text-slate-300">
          <div><span class="text-slate-400">Email:</span> admissions@ashford.test</div>
          <div><span class="text-slate-400">Phone:</span> (555) 010-2030</div>
          <div><span class="text-slate-400">Address:</span> 100 Campus Way, Ashford</div>
        </div>
      </div>

      <div class="md:col-span-7">
        <div class="grid gap-8 sm:grid-cols-3">
          <div>
            <div class="text-sm font-semibold text-slate-900 dark:text-slate-50">Explore</div>
            <ul class="mt-3 space-y-2 text-sm">
              <li><a class="text-slate-600 transition-colors hover:text-indigo-600 dark:text-slate-300 dark:hover:text-cyan-300" href="#programs">Programs</a></li>
              <li><a class="text-slate-600 transition-colors hover:text-indigo-600 dark:text-slate-300 dark:hover:text-cyan-300" href="#departments">Departments</a></li>
              <li><a class="text-slate-600 transition-colors hover:text-indigo-600 dark:text-slate-300 dark:hover:text-cyan-300" href="#news">News</a></li>
              <li><a class="text-slate-600 transition-colors hover:text-indigo-600 dark:text-slate-300 dark:hover:text-cyan-300" href="#events">Events</a></li>
              <li><a class="text-slate-600 transition-colors hover:text-indigo-600 dark:text-slate-300 dark:hover:text-cyan-300" href="#about">About</a></li>
            </ul>
          </div>
          <div>
            <div class="text-sm font-semibold text-slate-900 dark:text-slate-50">Admissions</div>
            <ul class="mt-3 space-y-2 text-sm">
              <li><a class="text-slate-600 transition-colors hover:text-indigo-600 dark:text-slate-300 dark:hover:text-cyan-300" href="#admissions">How to apply</a></li>
              <li><a class="text-slate-600 transition-colors hover:text-indigo-600 dark:text-slate-300 dark:hover:text-cyan-300" href="#admissions">Tuition & aid</a></li>
              <li><a class="text-slate-600 transition-colors hover:text-indigo-600 dark:text-slate-300 dark:hover:text-cyan-300" href="#visit">Visit campus</a></li>
            </ul>
          </div>
          <div>
            <div class="text-sm font-semibold text-slate-900 dark:text-slate-50">For students</div>
            <ul class="mt-3 space-y-2 text-sm">
              <li><a class="text-slate-600 transition-colors hover:text-indigo-600 dark:text-slate-300 dark:hover:text-cyan-300" href="#">Student portal</a></li>
              <li><a class="text-slate-600 transition-colors hover:text-indigo-600 dark:text-slate-300 dark:hover:text-cyan-300" href="#">Academic calendar</a></li>
              <li><a class="text-slate-600 transition-colors hover:text-indigo-600 dark:text-slate-300 dark:hover:text-cyan-300" href="#">Support services</a></li>
            </ul>
          </div>
        </div>

        <div class="mt-10 flex flex-col gap-3 border-t border-fuchsia-500/20 pt-6 sm:flex-row sm:items-center sm:justify-between">
          <div class="text-xs text-slate-400">
            © <?= date('Y') ?> <?= htmlspecialchars($app['site']['name']) ?>. All rights reserved.
          </div>
          <div class="flex items-center gap-4 text-xs">
            <a class="text-slate-400 transition-colors hover:text-fuchsia-300" href="#">Privacy</a>
            <a class="text-slate-400 transition-colors hover:text-fuchsia-300" href="#">Terms</a>
            <a class="text-slate-400 transition-colors hover:text-fuchsia-300" href="#">Accessibility</a>
          </div>
        </div>
      </div>
    </div>
  </div>
</footer>

