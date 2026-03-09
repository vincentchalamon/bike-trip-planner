const FEMINIST_NAMES = [
  "Annie Londonderry",
  "Alfonsina Strada",
  "Evelyne Carrer",
  "Beryl Burton",
  "Eileen Sheridan",
  "Marianne Martin",
  "Dervla Murphy",
  "Reine Bestel",
  "Junko Tabei",
  "Wangari Maathai",
  "Freya Stark",
  "Nellie Bly",
  "Bessie Coleman",
  "Valentina Tereshkova",
  "Gertrude Bell",
  "Isabelle Eberhardt",
  "Sacagawea",
  "Amelia Earhart",
  "Alexandra David-Neel",
  "Jeanne Baret",
  "Anne-France Dautheville",
  "Justine Duquesne",
  "Aurore Mancon",
];

export function getRandomTripName(): string {
  return (
    FEMINIST_NAMES[Math.floor(Math.random() * FEMINIST_NAMES.length)] ??
    "My Trip"
  );
}

export function getSuggestionName(currentTitle: string): string {
  const candidates = FEMINIST_NAMES.filter((n) => n !== currentTitle);
  return candidates[Math.floor(Math.random() * candidates.length)] ?? "My Trip";
}
