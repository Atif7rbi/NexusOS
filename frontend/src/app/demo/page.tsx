"use client";

import { useMemo, useState } from "react";
import {
  demoCompany,
  demoRoles,
  metrics,
  navigation,
  projects,
  recentActivity,
  sectionRows,
  type DemoSection,
} from "@/features/demo/demoData";

type Language = "ar" | "en";

const englishSectionLabels: Record<DemoSection, string> = {
  dashboard: "Dashboard",
  projects: "Projects",
  customers: "Customers",
  sales: "Sales",
  accounting: "Accounting",
  expenses: "Expenses",
  employees: "Employees",
  tasks: "Tasks",
  files: "Files",
  reports: "Reports",
  users: "Users & Roles",
  settings: "Settings",
};

const englishMetricLabels = ["Total Revenue", "Expenses", "Net Profit", "Outstanding Amounts"];

function StatusBadge({ children }: { children: string }) {
  return (
    <span className="inline-flex rounded-full border border-emerald-500/20 bg-emerald-500/10 px-2.5 py-1 text-xs font-medium text-emerald-700 dark:text-emerald-300">
      {children}
    </span>
  );
}

function Dashboard({ language, onUnavailable }: { language: Language; onUnavailable: () => void }) {
  const isArabic = language === "ar";

  return (
    <div className="space-y-6">
      <section className="grid gap-4 sm:grid-cols-2 xl:grid-cols-4">
        {metrics.map((metric, index) => (
          <article key={metric.label} className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/5">
            <p className="text-sm text-slate-500 dark:text-slate-400">{isArabic ? metric.label : englishMetricLabels[index]}</p>
            <p className="mt-3 text-2xl font-bold tracking-tight text-slate-950 dark:text-white">{metric.value}</p>
            <p className={`mt-2 text-xs font-semibold ${metric.tone === "positive" ? "text-emerald-600" : metric.tone === "warning" ? "text-amber-600" : "text-slate-500"}`}>
              {metric.change}
            </p>
          </article>
        ))}
      </section>

      <section className="grid gap-6 xl:grid-cols-[1.55fr_1fr]">
        <article className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/5">
          <div className="flex items-center justify-between gap-4">
            <div>
              <p className="text-sm font-semibold text-slate-950 dark:text-white">{isArabic ? "الأداء المالي" : "Financial Performance"}</p>
              <p className="mt-1 text-xs text-slate-500">{isArabic ? "الإيرادات والمصروفات خلال آخر 6 أشهر" : "Revenue and expenses over the last 6 months"}</p>
            </div>
            <StatusBadge>{isArabic ? "نمو مستقر" : "Stable growth"}</StatusBadge>
          </div>
          <div className="mt-8 flex h-56 items-end gap-3 rounded-xl bg-slate-50 p-4 dark:bg-black/10">
            {[52, 68, 58, 78, 72, 92].map((height, index) => (
              <div key={index} className="flex flex-1 items-end justify-center gap-1.5">
                <div className="w-2/5 rounded-t-md bg-slate-300 dark:bg-slate-600" style={{ height: `${Math.max(20, height - 25)}%` }} />
                <div className="w-2/5 rounded-t-md bg-indigo-500" style={{ height: `${height}%` }} />
              </div>
            ))}
          </div>
          <div className="mt-4 flex justify-center gap-6 text-xs text-slate-500">
            <span>■ {isArabic ? "الإيرادات" : "Revenue"}</span><span>■ {isArabic ? "المصروفات" : "Expenses"}</span>
          </div>
        </article>

        <article className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/5">
          <p className="text-sm font-semibold text-slate-950 dark:text-white">{isArabic ? "توزيع المشاريع" : "Project Distribution"}</p>
          <div className="mt-8 flex items-center justify-center">
            <div className="grid h-40 w-40 place-items-center rounded-full bg-[conic-gradient(#6366f1_0_58%,#22c55e_58%_78%,#f59e0b_78%_92%,#cbd5e1_92%)]">
              <div className="grid h-24 w-24 place-items-center rounded-full bg-white text-center dark:bg-slate-950">
                <div><p className="text-2xl font-bold">12</p><p className="text-xs text-slate-500">{isArabic ? "مشروعًا" : "Projects"}</p></div>
              </div>
            </div>
          </div>
          <div className="mt-6 grid grid-cols-2 gap-3 text-xs text-slate-600 dark:text-slate-300">
            <span>● {isArabic ? "قيد التنفيذ 7" : "In progress 7"}</span><span>● {isArabic ? "مكتملة 3" : "Completed 3"}</span><span>● {isArabic ? "تخطيط 1" : "Planning 1"}</span><span>● {isArabic ? "متوقفة 1" : "Paused 1"}</span>
          </div>
        </article>
      </section>

      <section className="grid gap-6 xl:grid-cols-[1.6fr_1fr]">
        <article className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/5">
          <div className="flex items-center justify-between border-b border-slate-200 px-5 py-4 dark:border-white/10">
            <div><p className="text-sm font-semibold">{isArabic ? "المشاريع النشطة" : "Active Projects"}</p><p className="mt-1 text-xs text-slate-500">{isArabic ? "متابعة التقدم والميزانية" : "Track progress and budget"}</p></div>
            <button onClick={onUnavailable} className="text-xs font-semibold text-indigo-600">{isArabic ? "عرض الكل" : "View all"}</button>
          </div>
          <div className="divide-y divide-slate-100 dark:divide-white/10">
            {projects.map((project) => (
              <div key={project.name} className="grid gap-3 px-5 py-4 sm:grid-cols-[1.4fr_1fr_1fr] sm:items-center">
                <div><p className="text-sm font-semibold">{project.name}</p><p className="mt-1 text-xs text-slate-500">{project.client}</p></div>
                <div><div className="mb-1 flex justify-between text-xs"><span>{isArabic ? "التقدم" : "Progress"}</span><span>{project.progress}%</span></div><div className="h-2 rounded-full bg-slate-100 dark:bg-white/10"><div className="h-full rounded-full bg-indigo-500" style={{ width: `${project.progress}%` }} /></div></div>
                <div className={isArabic ? "sm:text-left" : "sm:text-right"}><p className="text-sm font-semibold">{project.budget}</p><p className="mt-1 text-xs text-slate-500">{project.status}</p></div>
              </div>
            ))}
          </div>
        </article>

        <article className="rounded-2xl border border-slate-200 bg-white p-5 shadow-sm dark:border-white/10 dark:bg-white/5">
          <p className="text-sm font-semibold">{isArabic ? "آخر النشاطات" : "Recent Activity"}</p>
          <div className="mt-5 space-y-5">
            {recentActivity.map((activity) => (
              <div key={activity.title} className="flex gap-3">
                <span className="mt-1 h-2.5 w-2.5 shrink-0 rounded-full bg-indigo-500" />
                <div><p className="text-sm leading-6">{activity.title}</p><p className="mt-1 text-xs text-slate-500">{activity.meta}</p></div>
              </div>
            ))}
          </div>
        </article>
      </section>
    </div>
  );
}

function GenericSection({ section, language, onUnavailable }: { section: Exclude<DemoSection, "dashboard" | "settings">; language: Language; onUnavailable: () => void }) {
  const rows = sectionRows[section];
  const headers = Object.keys(rows[0] ?? {});
  const isArabic = language === "ar";
  const title = isArabic ? navigation.find((item) => item.id === section)?.label ?? "القسم" : englishSectionLabels[section];

  return (
    <div className="space-y-6">
      <section className="grid gap-4 md:grid-cols-3">
        {[isArabic ? "إجمالي السجلات" : "Total Records", isArabic ? "نشط هذا الشهر" : "Active This Month", isArabic ? "بحاجة للمتابعة" : "Needs Follow-up"].map((label, index) => (
          <article key={label} className="rounded-2xl border border-slate-200 bg-white p-5 dark:border-white/10 dark:bg-white/5"><p className="text-sm text-slate-500">{label}</p><p className="mt-3 text-3xl font-bold">{index === 0 ? rows.length + 9 : index === 1 ? rows.length + 4 : 2}</p></article>
        ))}
      </section>

      <section className="overflow-hidden rounded-2xl border border-slate-200 bg-white shadow-sm dark:border-white/10 dark:bg-white/5">
        <div className="flex flex-col gap-3 border-b border-slate-200 p-5 sm:flex-row sm:items-center sm:justify-between dark:border-white/10">
          <div><h2 className="font-bold">{isArabic ? `سجل ${title}` : `${title} Records`}</h2><p className="mt-1 text-xs text-slate-500">{isArabic ? "بيانات تجريبية توضح طريقة العرض والإدارة" : "Demo data illustrating the intended workflow"}</p></div>
          <div className="flex gap-2"><input className="w-44 rounded-xl border border-slate-200 bg-transparent px-3 py-2 text-sm outline-none focus:border-indigo-500 dark:border-white/10" placeholder={isArabic ? "بحث..." : "Search..."} /><button onClick={onUnavailable} className="rounded-xl bg-indigo-600 px-4 py-2 text-sm font-semibold text-white">{isArabic ? "إضافة جديد" : "Add New"}</button></div>
        </div>
        <div className="overflow-x-auto">
          <table className={`min-w-full text-sm ${isArabic ? "text-right" : "text-left"}`}>
            <thead className="bg-slate-50 text-xs text-slate-500 dark:bg-black/10"><tr>{headers.map((header) => <th key={header} className="whitespace-nowrap px-5 py-3 font-medium">{header}</th>)}<th className="px-5 py-3" /></tr></thead>
            <tbody className="divide-y divide-slate-100 dark:divide-white/10">
              {rows.map((row, index) => <tr key={index} className="hover:bg-slate-50/70 dark:hover:bg-white/[0.03]">{headers.map((header) => <td key={header} className="whitespace-nowrap px-5 py-4">{row[header]}</td>)}<td className="px-5 py-4"><button onClick={onUnavailable} className="font-bold text-indigo-600">{isArabic ? "عرض" : "View"}</button></td></tr>)}
            </tbody>
          </table>
        </div>
      </section>
    </div>
  );
}

function Settings({ language, onUnavailable }: { language: Language; onUnavailable: () => void }) {
  const isArabic = language === "ar";
  const items = isArabic ? ["بيانات الشركة", "التفضيلات العامة", "إعدادات الفواتير", "التنبيهات"] : ["Company Profile", "General Preferences", "Invoice Settings", "Notifications"];
  return <div className="grid gap-5 lg:grid-cols-2">{items.map((title) => <article key={title} className="rounded-2xl border border-slate-200 bg-white p-6 dark:border-white/10 dark:bg-white/5"><h2 className="font-bold">{title}</h2><p className="mt-2 text-sm text-slate-500">{isArabic ? "إعدادات تجريبية توضح خيارات تخصيص NexusOS لكل شركة." : "Demo settings illustrating how NexusOS can be customized for each company."}</p><button onClick={onUnavailable} className="mt-5 rounded-xl border border-slate-200 px-4 py-2 text-sm font-semibold dark:border-white/10">{isArabic ? "إدارة الإعدادات" : "Manage Settings"}</button></article>)}</div>;
}

export default function DemoPage() {
  const [section, setSection] = useState<DemoSection>("dashboard");
  const [role, setRole] = useState(demoRoles[0]);
  const [sidebarOpen, setSidebarOpen] = useState(false);
  const [language, setLanguage] = useState<Language>("ar");
  const [toastVisible, setToastVisible] = useState(false);
  const active = useMemo(() => navigation.find((item) => item.id === section), [section]);
  const isArabic = language === "ar";

  function showUnderDevelopment() {
    setToastVisible(true);
    window.setTimeout(() => setToastVisible(false), 2600);
  }

  const activeLabel = isArabic ? active?.label : englishSectionLabels[section];

  return (
    <main dir={isArabic ? "rtl" : "ltr"} lang={language} className="min-h-screen bg-slate-100 text-slate-900 dark:bg-slate-950 dark:text-slate-100">
      <div className="flex min-h-screen">
        <aside className={`${sidebarOpen ? "translate-x-0" : isArabic ? "translate-x-full" : "-translate-x-full"} fixed inset-y-0 ${isArabic ? "right-0 border-l" : "left-0 border-r"} z-40 flex w-72 flex-col border-slate-200 bg-slate-950 text-white transition-transform lg:static lg:translate-x-0`}>
          <div className="border-b border-white/10 p-6"><p className="text-2xl font-black tracking-tight">Nexus<span className="text-indigo-400">OS</span></p><p className="mt-2 text-xs text-slate-400">{isArabic ? "نسخة تجريبية تجارية" : "Commercial Demo Preview"}</p></div>
          <div className="border-b border-white/10 p-4"><p className="text-sm font-semibold">{demoCompany.shortName}</p><p className="mt-1 text-xs text-slate-400">{isArabic ? "بيئة تجريبية · بيانات وهمية" : "Demo environment · Sample data"}</p></div>
          <nav className="flex-1 space-y-1 overflow-y-auto p-3">{navigation.map((item) => <button key={item.id} onClick={() => { setSection(item.id); setSidebarOpen(false); }} className={`flex w-full items-center gap-3 rounded-xl px-3 py-2.5 ${isArabic ? "text-right" : "text-left"} text-sm transition ${section === item.id ? "bg-indigo-600 text-white" : "text-slate-300 hover:bg-white/10 hover:text-white"}`}><span className="w-5 text-center">{item.icon}</span><span>{isArabic ? item.label : englishSectionLabels[item.id]}</span></button>)}</nav>
          <div className="border-t border-white/10 p-4 text-xs text-slate-400">{isArabic ? "هذه النسخة للعرض واكتشاف المتطلبات فقط." : "This preview is for demonstration and requirements discovery only."}</div>
        </aside>

        {sidebarOpen && <button aria-label={isArabic ? "إغلاق القائمة" : "Close menu"} className="fixed inset-0 z-30 bg-black/50 lg:hidden" onClick={() => setSidebarOpen(false)} />}

        <section className="min-w-0 flex-1">
          <header className="sticky top-0 z-20 flex h-20 items-center justify-between border-b border-slate-200 bg-white/90 px-4 backdrop-blur sm:px-7 dark:border-white/10 dark:bg-slate-950/90">
            <div className="flex items-center gap-3"><button onClick={() => setSidebarOpen(true)} className="rounded-lg border border-slate-200 px-3 py-2 lg:hidden dark:border-white/10">☰</button><div><h1 className="font-bold sm:text-lg">{activeLabel}</h1><p className="hidden text-xs text-slate-500 sm:block">{demoCompany.name} · {demoCompany.period}</p></div></div>
            <div className="flex items-center gap-2">
              <div className="flex rounded-xl border border-slate-200 p-1 text-xs font-bold dark:border-white/10"><button onClick={() => setLanguage("ar")} className={`rounded-lg px-3 py-1.5 ${isArabic ? "bg-indigo-600 text-white" : "text-slate-500"}`}>عربي</button><button onClick={() => setLanguage("en")} className={`rounded-lg px-3 py-1.5 ${!isArabic ? "bg-indigo-600 text-white" : "text-slate-500"}`}>EN</button></div>
              <select value={role} onChange={(event) => setRole(event.target.value)} className="rounded-xl border border-slate-200 bg-transparent px-3 py-2 text-sm dark:border-white/10">{demoRoles.map((item) => <option key={item}>{item}</option>)}</select>
              <div className="grid h-10 w-10 place-items-center rounded-full bg-indigo-100 font-bold text-indigo-700 dark:bg-indigo-500/20 dark:text-indigo-300">A</div>
            </div>
          </header>

          <div className="p-4 sm:p-7">
            <div className="mx-auto mb-6 max-w-2xl rounded-2xl border border-amber-300 bg-amber-50 px-5 py-3 text-center text-sm font-bold text-amber-800 shadow-sm dark:border-amber-500/30 dark:bg-amber-500/10 dark:text-amber-300">
              <div>⚠ {isArabic ? "وضع العرض التجريبي · لا توجد عمليات حقيقية" : "Demo Mode · No real operations"}</div>
              <div className="mt-1 text-xs font-normal opacity-80">{isArabic ? "جميع البيانات المعروضة تجريبية ولأغراض العرض فقط" : "All displayed data is fictional and for demonstration purposes only"}</div>
            </div>

            <div className="mb-6"><p className="text-xs font-semibold text-indigo-600">{isArabic ? "مرحبًا، أحمد" : "Welcome, Ahmed"}</p><h2 className="mt-1 text-2xl font-black">{activeLabel}</h2><p className="mt-2 text-sm text-slate-500">{isArabic ? `أنت تستعرض النظام بصلاحيات: ${role}` : `You are viewing the system with the role: ${role}`}</p></div>

            {section === "dashboard" ? <Dashboard language={language} onUnavailable={showUnderDevelopment} /> : section === "settings" ? <Settings language={language} onUnavailable={showUnderDevelopment} /> : <GenericSection section={section} language={language} onUnavailable={showUnderDevelopment} />}
          </div>
        </section>
      </div>

      {toastVisible && (
        <div className={`fixed bottom-6 ${isArabic ? "left-6" : "right-6"} z-50 w-[min(22rem,calc(100vw-3rem))] rounded-2xl border border-slate-200 bg-white p-4 shadow-2xl dark:border-white/10 dark:bg-slate-900`}>
          <p className="font-bold">{isArabic ? "قيد التطوير" : "Under Development"}</p>
          <p className="mt-1 text-sm text-slate-500">{isArabic ? "هذه الميزة قيد التطوير وستكون متاحة قريبًا." : "This feature is currently under development and will be available soon."}</p>
        </div>
      )}
    </main>
  );
}
