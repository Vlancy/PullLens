/**
 * The product mark, as served from the web root.
 *
 * It is the magnifier cropped out of `public/logo.png`, on a transparent
 * background, which is also what the browser tab icons are generated from. Being
 * transparent black artwork, it needs inverting wherever it sits on a dark
 * surface, so anything rendering it pairs this with `dark:invert`.
 */
export const MARK_SRC = '/favicon.png';
