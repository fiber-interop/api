<?php
// Local stand-in for fiber-interop/scope until that package has a composer.json. Same public API.
if (!class_exists('Scope', false)) {
    final class Scope
    {
        private static ?Scope $root = null;
        private static ?\WeakMap $stacks = null;
        private static array $mainStack = [];

        public function __construct() {}

        public static function getRootScope(): Scope
        {
            return self::$root ??= new Scope();
        }

        public static function getCurrent(): Scope
        {
            $f = \Fiber::getCurrent();
            $stack = $f === null ? self::$mainStack : ((self::$stacks ??= new \WeakMap())[$f] ?? []);
            return $stack ? $stack[count($stack) - 1] : self::getRootScope();
        }

        public function run(callable $f, mixed ...$args): mixed
        {
            $fiber = \Fiber::getCurrent();
            self::$stacks ??= new \WeakMap();
            if ($fiber === null) { self::$mainStack[] = $this; } else { $s = self::$stacks[$fiber] ?? []; $s[] = $this; self::$stacks[$fiber] = $s; }
            try {
                return $f(...$args);
            } finally {
                if ($fiber === null) { array_pop(self::$mainStack); } else { $s = self::$stacks[$fiber]; array_pop($s); self::$stacks[$fiber] = $s; }
            }
        }
    }
}
