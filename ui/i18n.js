import { cloneElement, isValidElement } from "react";

/**
 * Strings. Drupal.t is used when the page provides it.
 */

export function __(text) {
  if (typeof Drupal !== "undefined" && Drupal.t) {
    return Drupal.t(text);
  }
  return text;
}

export function sprintf(text, ...args) {
  let index = 0;
  return String(text).replace(/%[ds]/g, () => String(args[index++]));
}

export function _n(single, plural, count) {
  return __(Number(count) === 1 ? single : plural);
}

/**
 * Replaces `<tag>` markers with the matching React element.
 *
 * @param {string} text
 * @param {Record<string, import('react').ReactElement>} map
 */
export function createInterpolateElement(text, map) {
  const parts = String(text).split(/(<\/?[a-z0-9]+>)/gi);
  const out = [];
  for (let index = 0; index < parts.length; index++) {
    const open = /^<([a-z0-9]+)>$/i.exec(parts[index]);
    if (!open) {
      if (parts[index] && !/^<\/[a-z0-9]+>$/i.test(parts[index])) {
        out.push(parts[index]);
      }
      continue;
    }
    const tag = open[1];
    const element = map[tag];
    if (!isValidElement(element)) {
      continue;
    }
    const children = [];
    const close = `</${tag}>`;
    index += 1;
    while (index < parts.length && parts[index].toLowerCase() !== close.toLowerCase()) {
      children.push(parts[index]);
      index += 1;
    }
    out.push(
      cloneElement(
        element,
        { key: out.length },
        children.length === 1 ? children[0] : children,
      ),
    );
  }
  return out;
}
