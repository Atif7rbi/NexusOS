import { ReactNode } from "react";

/**
 * Dashboard widget definition used by the engine.
 */
export type WorkspaceWidgetRender = {
  id: string;
  order: number;
  element: ReactNode;
};

type WorkspaceEngineProps = {
  widgets: WorkspaceWidgetRender[];
};

/**
 * Renders workspace widgets ordered by their position.
 */
export function WorkspaceEngine({
  widgets,
}: WorkspaceEngineProps) {
  const orderedWidgets = [...widgets].sort(
    (a, b) => a.order - b.order,
  );

  return (
    <div className="grid gap-6 xl:grid-cols-2">
      {orderedWidgets.map((widget) => (
        <div key={widget.id}>
          {widget.element}
        </div>
      ))}
    </div>
  );
}
