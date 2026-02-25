# ADR-014 — Dynamic Engine Management Design Pattern

**Status:** Accepted

**Date:** 2026-02-25

**Decision Makers:** Lead Developer

**Context:** Bike Trip Planner MVP — Local-first bikepacking trip generator

---

## Context and Problem Statement

Our application relies on multiple distinct calculation engines (e.g., `PacingEngine`, `PricingHeuristicEngine`). Currently, these classes are strictly `final`, and injecting them directly into our services violates the Dependency Inversion Principle (DIP).

We need an architectural pattern to abstract these engines behind interfaces. However, the ecosystem of engines is expected to grow. We evaluated two main patterns to centralize engine access:

- **The Facade Pattern**: Centralizes calls and respects the Law of Demeter.
- **The Registry (or Typed Service Locator) Pattern**: Provides an engine instance based on a requested interface.

Using a Facade requires manual modification of the Facade class whenever a new engine is introduced, nullifying the benefits of Symfony's autowiring/autoconfiguration capabilities.

## Decision

We have decided to implement a **Typed Registry Pattern** (acting as a domain-specific Service Locator) to manage and retrieve our engine interfaces.

In the Symfony context, this will be powered by a `#[AutowireLocator]` (or tagged iterators), allowing the application to automatically discover and register new engines implementing a base marker interface or specific annotations, without modifying the Registry itself.

## Rationale and Pragmatic Trade-offs

This decision relies on a pragmatic balance between architectural purity and framework synergy:

- **Favoring the Open/Closed Principle (OCP) over the Law of Demeter (LoD)**: While the Registry pattern forces the client code to chain method calls (e.g., `$this->registry->getEngine(PricingEngineInterface::class)->estimatePrice(...)`), which is a known violation of the Law of Demeter, it heavily favors the Open/Closed Principle. Adding a new engine into the system now requires zero changes to existing routing or registry code.
- **Symfony Ecosystem Synergy**: Symfony excels at dynamic dependency resolution. By using a Registry backed by Symfony's Service Container (via `ServiceLocatorTrait` or `#[AutowireLocator]`), we benefit from lazy-loading. Engines are only instantiated if and when they are requested by the client code, optimizing memory and performance.
- **Testing and Interface Segregation (ISP)**: Unlike a monolithic Facade that forces a service to depend on a massive interface containing methods it doesn't need, the Registry allows the client to explicitly request the exact, segregated interface it requires. Mocking the Registry in unit tests using generic `@template` annotations provides strict type safety and IDE autocompletion.

## Consequences

- **Positive**: New engines can be added simply by creating the class and implementing the interface. Symfony autowires them into the Registry seamlessly.
- **Positive**: Memory efficiency due to lazy-loading engines only when queried.
- **Negative (Accepted risk)**: Slight structural coupling to the Registry interface.
- **Negative (Accepted risk)**: Violation of the Law of Demeter. The client code "knows" that the Registry holds engines and asks for them before using them, instead of just sending a message.

## References

- **SOLID Principles (Robert C. Martin)**: [Dependency Inversion Principle & Open/Closed Principle](https://en.wikipedia.org/wiki/SOLID)
- **The Law of Demeter (Principle of Least Knowledge)**: [Law of Demeter](https://en.wikipedia.org/wiki/Law_of_Demeter)
- **Symfony Subscribers & Locators**: [Symfony Documentation: Service Subscribers & Locators](https://symfony.com/doc/current/service_container/service_subscribers_locators.html)
