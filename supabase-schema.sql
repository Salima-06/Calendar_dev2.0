-- =====================================================================
-- Ducks In A Row: Supabase schema
-- Run this once in Supabase: SQL Editor -> New query -> paste -> Run.
--
-- Users sign in with Clerk. Supabase trusts Clerk's session token (see the
-- setup notes), and every row is tied to the Clerk user id (a text value like
-- "user_2abc..."). Row Level Security makes sure people can only see and change
-- their own rows.
-- =====================================================================

-- Helper: the signed-in Clerk user id taken from the token.
create or replace function public.requesting_user_id()
returns text
language sql stable
as $$
  select nullif(auth.jwt() ->> 'sub', '')
$$;

-- ---------------------------------------------------------------------
-- profiles: one row per user (filled in by onboarding)
-- ---------------------------------------------------------------------
create table if not exists public.profiles (
  user_id            text primary key default public.requesting_user_id(),
  full_name          text,
  school             text,
  program            text,
  year_of_study      text,
  timezone           text,
  week_starts_on     text not null default 'monday' check (week_starts_on in ('monday','sunday')),
  reminders_enabled  boolean not null default true,
  onboarded          boolean not null default false,
  created_at         timestamptz not null default now(),
  updated_at         timestamptz not null default now()
);

-- ---------------------------------------------------------------------
-- courses: the courses a user wants on their calendar
-- (course code + section + lab/tutorial sections; no availability checking)
-- ---------------------------------------------------------------------
create table if not exists public.courses (
  id            uuid primary key default gen_random_uuid(),
  user_id       text not null default public.requesting_user_id(),
  course_code   text not null,              -- e.g. COMP 1405
  section       text,                       -- e.g. A
  lab_sections  text[] not null default '{}', -- e.g. {A1,B2}
  term          text,                       -- e.g. Winter 2027
  created_at    timestamptz not null default now()
);
create index if not exists courses_user_idx on public.courses (user_id);

-- ---------------------------------------------------------------------
-- events: items on the calendar (used by the calendar page later)
-- ---------------------------------------------------------------------
create table if not exists public.events (
  id          uuid primary key default gen_random_uuid(),
  user_id     text not null default public.requesting_user_id(),
  title       text not null,
  kind        text not null default 'event',  -- event | class | deadline | task
  starts_at   timestamptz not null,
  ends_at     timestamptz,
  all_day     boolean not null default false,
  notes       text,
  course_id   uuid references public.courses(id) on delete set null,
  created_at  timestamptz not null default now()
);
create index if not exists events_user_start_idx on public.events (user_id, starts_at);

-- Keep updated_at fresh on profiles.
create or replace function public.touch_updated_at()
returns trigger language plpgsql as $$
begin
  new.updated_at = now();
  return new;
end $$;

drop trigger if exists profiles_touch on public.profiles;
create trigger profiles_touch before update on public.profiles
  for each row execute function public.touch_updated_at();

-- ---------------------------------------------------------------------
-- Row Level Security: each person only touches their own rows
-- ---------------------------------------------------------------------
alter table public.profiles enable row level security;
alter table public.courses  enable row level security;
alter table public.events   enable row level security;

drop policy if exists "own profile" on public.profiles;
create policy "own profile" on public.profiles
  for all to authenticated
  using       ((select public.requesting_user_id()) = user_id)
  with check  ((select public.requesting_user_id()) = user_id);

drop policy if exists "own courses" on public.courses;
create policy "own courses" on public.courses
  for all to authenticated
  using       ((select public.requesting_user_id()) = user_id)
  with check  ((select public.requesting_user_id()) = user_id);

drop policy if exists "own events" on public.events;
create policy "own events" on public.events
  for all to authenticated
  using       ((select public.requesting_user_id()) = user_id)
  with check  ((select public.requesting_user_id()) = user_id);

-- Signed-out visitors (the anon role) get nothing.
revoke all on public.profiles, public.courses, public.events from anon;
grant select, insert, update, delete on public.profiles, public.courses, public.events to authenticated;
