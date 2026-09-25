export type PlatformLink = {
    name: string;
    platform: string;
    description: string;
    href: string;
};

export const modrinth: PlatformLink = {
    name: 'Modrinth',
    platform: 'Fabric + Paper',
    description:
        'One listing with both the Fabric mod and the Paper plugin builds.',
    href: 'https://modrinth.com/plugin/enderman-grief-control',
};

export const curseforgeFabric: PlatformLink = {
    name: 'CurseForge',
    platform: 'Fabric mod',
    description: 'The Fabric build for singleplayer worlds and modded servers.',
    href: 'https://www.curseforge.com/minecraft/mc-mods/enderman-grief-control',
};

export const curseforgePaper: PlatformLink = {
    name: 'CurseForge',
    platform: 'Paper / Bukkit plugin',
    description: 'The server plugin build for Paper and Bukkit-based servers.',
    href: 'https://www.curseforge.com/minecraft/bukkit-plugins/enderman-grief-control',
};

export const github: PlatformLink = {
    name: 'GitHub',
    platform: 'Source code',
    description: 'Source, issues, and release history for the mod and plugin.',
    href: 'https://github.com/Enderman-Grief-Control/Enderman-Grief-Control_Plugin-Mod',
};

export const distributions: PlatformLink[] = [
    modrinth,
    curseforgeFabric,
    curseforgePaper,
];

export const dashboardHref =
    'https://enderman-grief-control.onrender.com/dashboard';
export const portfolioHref = 'https://jack-underhill.netlify.app/';
