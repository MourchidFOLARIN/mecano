import type { NextConfig } from "next";
import path from "node:path";

/**
 * Hôtes autorisés pour `next/image`.
 *
 * Les photos et vidéos des annonces sont servies par Cloudinary : sans cette
 * entrée, chaque `<Image>` pointant sur une URL d'annonce échoue au rendu
 * (`hostname is not configured under images`). `placehold.co` couvre les
 * visuels de remplacement tant que le catalogue n'est pas branché sur l'API.
 *
 * Le chemin est restreint au compte Cloudinary du projet quand
 * `NEXT_PUBLIC_CLOUDINARY_CLOUD_NAME` est défini : sinon l'optimiseur d'images
 * du site accepterait de retailler n'importe quel média d'un compte tiers.
 */
const cloudName = process.env.NEXT_PUBLIC_CLOUDINARY_CLOUD_NAME;

const nextConfig: NextConfig = {
  turbopack: {
    root: path.resolve(__dirname),
  },
  images: {
    remotePatterns: [
      { protocol: "https", hostname: "placehold.co" },
      {
        protocol: "https",
        hostname: "res.cloudinary.com",
        pathname: cloudName ? `/${cloudName}/**` : "/**",
      },
    ],
  },
};

export default nextConfig;
