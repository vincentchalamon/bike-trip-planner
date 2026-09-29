/**
 * Reads the secret an emailed link carries in its fragment, then erases it.
 *
 * The verify links put their token after the `#` because a browser never sends
 * the fragment: it stays out of the server's access logs and out of the
 * `Referer` of the page's own requests. Erasing it right away keeps it out of
 * the history entry, and out of any error report that records the page URL.
 */
export function takeUrlFragment(): string {
  const fragment = window.location.hash.replace(/^#/, "");
  if (fragment !== "") {
    window.history.replaceState(
      window.history.state,
      "",
      window.location.pathname + window.location.search,
    );
  }

  return fragment;
}
