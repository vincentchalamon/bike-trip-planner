"use client";

import { Component } from "react";
import type { ReactNode, ErrorInfo } from "react";
import { ErrorBoundaryContent } from "@/components/error-boundary-content";
import { logger } from "@/lib/logger";

interface Props {
  children: ReactNode;
}

interface State {
  error: Error | null;
}

export class TripPlannerErrorBoundary extends Component<Props, State> {
  constructor(props: Props) {
    super(props);
    this.state = { error: null };
  }

  static getDerivedStateFromError(error: Error): State {
    return { error };
  }

  componentDidCatch(error: Error, errorInfo: ErrorInfo): void {
    logger.error("TripPlanner error", {
      error,
      componentStack: errorInfo.componentStack,
    });
  }

  render(): ReactNode {
    if (this.state.error) {
      return (
        <ErrorBoundaryContent
          error={this.state.error}
          reset={() => this.setState({ error: null })}
        />
      );
    }

    return this.props.children;
  }
}
