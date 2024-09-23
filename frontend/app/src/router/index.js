import { createRouter, createWebHistory } from 'vue-router';
import Home from '../views/Home.vue'; // Create this component

const routes = [
  {
    path: '/',
    name: 'Home',
    component: Home,
  },
  // Add more routes here
];

const router = createRouter({
  history: createWebHistory(),
  routes,
});

export default router;
