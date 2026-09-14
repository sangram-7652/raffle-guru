import { describe, expect, test } from "vitest";
import { competitionAvailability } from "@/lib/competitionAvailability";

describe("competitionAvailability", () => {
  test("only 'live' (and 'ending-soon') are purchasable", () => {
    expect(competitionAvailability("live").purchasable).toBe(true);
    expect(competitionAvailability("ending-soon").purchasable).toBe(true);
    expect(competitionAvailability("sold-out").purchasable).toBe(false);
    expect(competitionAvailability("closed").purchasable).toBe(false);
    expect(competitionAvailability("draw-pending").purchasable).toBe(false);
    expect(competitionAvailability("drawn").purchasable).toBe(false);
  });

  test("closed and draw-pending are never labeled 'Drawn' — a draw hasn't happened yet", () => {
    expect(competitionAvailability("closed").label).not.toMatch(/drawn/i);
    expect(competitionAvailability("draw-pending").label).not.toMatch(/drawn/i);
  });

  test("each non-purchasable status has a distinct, truthful label", () => {
    expect(competitionAvailability("sold-out").label).toBe("Sold out");
    expect(competitionAvailability("closed").label).toBe("Closed");
    expect(competitionAvailability("draw-pending").label).toBe("Draw pending");
    expect(competitionAvailability("drawn").label).toBe("Drawn");
  });
});
