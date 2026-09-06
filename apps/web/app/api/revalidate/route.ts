import { createHmac, timingSafeEqual } from "node:crypto";
import { revalidateTag } from "next/cache";

/**
 * Webhook de revalidation ISR appelé par l'API Laravel (ADR 0009).
 *
 * L'API ne connaît pas les pages : elle annonce des **étiquettes** invalidées
 * (`car:{slug}`, `cars`, `home`, `services`, `posts`, `post:{slug}`,
 * `settings`) et c'est ici qu'elles sont purgées. Chaque route porte en plus un
 * `revalidate = 3600` : un webhook perdu ne laisse donc pas une page périmée
 * indéfiniment.
 *
 * Le mode d'échec est silencieux côté visiteur — une donnée fausse servie sans
 * la moindre erreur. Toute demande refusée est donc journalisée, et un statut
 * non-2xx est renvoyé pour que le job Laravel réessaie puis alerte.
 */

/** `node:crypto` et le cache serveur : jamais l'exécution en périphérie. */
export const runtime = "nodejs";

/** Un webhook ne se met pas en cache, même partiellement. */
export const dynamic = "force-dynamic";

/** Rejet au-delà de 5 minutes d'écart : une requête rejouée n'a plus d'effet. */
const TIMESTAMP_TOLERANCE_SECONDS = 300;

/** Une invalidation légitime porte quelques étiquettes, jamais des centaines. */
const MAX_TAGS = 50;
const MAX_TAG_LENGTH = 256;

/** Limitation de débit : sans elle, un attaquant purgerait le cache en boucle. */
const RATE_LIMIT_WINDOW_MS = 60_000;
const RATE_LIMIT_MAX_REQUESTS = 30;

/**
 * Compteur en mémoire, donc propre à chaque instance serverless : il freine un
 * abus, il ne le bloque pas absolument. La signature reste la vraie défense.
 */
const hits = new Map<string, { count: number; resetAt: number }>();

function rateLimited(key: string): boolean {
  const now = Date.now();
  const entry = hits.get(key);

  if (entry === undefined || now >= entry.resetAt) {
    hits.set(key, { count: 1, resetAt: now + RATE_LIMIT_WINDOW_MS });

    // Purge opportuniste : la table ne doit pas grossir avec les IP vues une fois.
    if (hits.size > 1000) {
      for (const [seen, value] of hits) {
        if (now >= value.resetAt) hits.delete(seen);
      }
    }

    return false;
  }

  entry.count += 1;

  return entry.count > RATE_LIMIT_MAX_REQUESTS;
}

function clientKey(request: Request): string {
  const forwarded = request.headers.get("x-forwarded-for");

  return forwarded?.split(",")[0]?.trim() || "inconnu";
}

/** Comparaison à temps constant : une comparaison naïve fuite le secret. */
function signatureMatches(expected: string, received: string): boolean {
  const a = Buffer.from(expected, "utf8");
  const b = Buffer.from(received, "utf8");

  if (a.length !== b.length) return false;

  return timingSafeEqual(a, b);
}

function refuse(status: number, code: string, message: string): Response {
  return Response.json({ error: { code, message } }, { status });
}

interface RevalidatePayload {
  tags: string[];
  timestamp: number;
}

/** Le corps est authentifié, mais sa forme reste à vérifier. */
function parsePayload(raw: string): RevalidatePayload | null {
  let parsed: unknown;

  try {
    parsed = JSON.parse(raw);
  } catch {
    return null;
  }

  if (typeof parsed !== "object" || parsed === null) return null;

  const { tags, timestamp } = parsed as { tags?: unknown; timestamp?: unknown };

  if (!Array.isArray(tags) || tags.length === 0 || tags.length > MAX_TAGS) return null;
  if (typeof timestamp !== "number" || !Number.isFinite(timestamp)) return null;

  const clean = tags.filter(
    (tag): tag is string =>
      typeof tag === "string" && tag.length > 0 && tag.length <= MAX_TAG_LENGTH,
  );

  if (clean.length !== tags.length) return null;

  return { tags: clean, timestamp };
}

export async function POST(request: Request): Promise<Response> {
  const secret = process.env.REVALIDATE_SECRET;

  if (!secret) {
    console.error("[revalidate] REVALIDATE_SECRET absent : webhook inopérant.");

    return refuse(500, "MISCONFIGURED", "Le webhook de revalidation n'est pas configuré.");
  }

  if (rateLimited(clientKey(request))) {
    return refuse(429, "RATE_LIMITED", "Trop de demandes de revalidation.");
  }

  const received = request.headers.get("x-revalidate-signature");

  if (!received) {
    return refuse(401, "SIGNATURE_MISSING", "En-tête X-Revalidate-Signature absent.");
  }

  // Le corps brut, jamais un objet resérialisé : la signature porte sur les
  // octets envoyés, et `JSON.stringify` ne les reproduit pas à l'identique.
  const raw = await request.text();
  const expected = createHmac("sha256", secret).update(raw).digest("hex");

  if (!signatureMatches(expected, received)) {
    console.warn("[revalidate] Signature invalide, demande rejetée.");

    return refuse(401, "SIGNATURE_INVALID", "Signature de revalidation invalide.");
  }

  const payload = parsePayload(raw);

  if (payload === null) {
    return refuse(400, "INVALID_PAYLOAD", "Corps de revalidation invalide.");
  }

  const driftSeconds = Math.abs(Date.now() / 1000 - payload.timestamp);

  if (driftSeconds > TIMESTAMP_TOLERANCE_SECONDS) {
    console.warn(`[revalidate] Horodatage hors tolérance (${Math.round(driftSeconds)}s).`);

    return refuse(400, "STALE_TIMESTAMP", "Horodatage trop ancien ou trop éloigné.");
  }

  for (const tag of payload.tags) {
    revalidateTag(tag, "max");
  }

  return Response.json({ revalidated: payload.tags, at: new Date().toISOString() });
}
