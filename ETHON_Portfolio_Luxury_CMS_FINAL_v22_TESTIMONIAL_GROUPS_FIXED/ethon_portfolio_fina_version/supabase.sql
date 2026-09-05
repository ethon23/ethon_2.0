-- ETHON Portfolio CMS: run this once in Supabase SQL Editor.
create table if not exists public.cms_state (
  id integer primary key,
  data jsonb not null default '{}'::jsonb,
  updated_at timestamptz not null default now()
);

insert into public.cms_state (id, data)
values (1, '{"projects":[],"testimonials":[],"messages":[],"conversations":[],"settings":{}}'::jsonb)
on conflict (id) do nothing;

-- Storage bucket for admin-uploaded project/testimonial images.
insert into storage.buckets (id, name, public)
values ('portfolio', 'portfolio', true)
on conflict (id) do update set public=true;
