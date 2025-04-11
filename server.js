const express = require('express');
const mongoose = require('mongoose');
const cors = require('cors');
const jwt = require('jsonwebtoken');
const bcrypt = require('bcryptjs');
const http = require('http');
const socketIo = require('socket.io');

const app = express();
const server = http.createServer(app);
const io = socketIo(server, { cors: { origin: '*' } });

app.use(cors());
app.use(express.json());

// Conectar a MongoDB (usará la variable de entorno MONGODB_URI)
mongoose.connect(process.env.MONGODB_URI, { useNewUrlParser: true, useUnifiedTopology: true })
    .then(() => console.log('Conectado a MongoDB'))
    .catch(err => console.error('Error de conexión:', err));

// Modelos
const UserSchema = new mongoose.Schema({
    email: { type: String, required: true, unique: true },
    password: { type: String, required: true },
    name: { type: String, required: true }
});
const User = mongoose.model('User', UserSchema);

const AvailabilitySchema = new mongoose.Schema({
    userId: { type: mongoose.Schema.Types.ObjectId, ref: 'User' },
    groupId: { type: mongoose.Schema.Types.ObjectId, ref: 'Group' },
    timezone: String,
    startTime: String,
    endTime: String
});
const Availability = mongoose.model('Availability', AvailabilitySchema);

const GroupSchema = new mongoose.Schema({
    name: String,
    description: String,
    members: [{ type: mongoose.Schema.Types.ObjectId, ref: 'User' }],
    missionTime: Date
});
const Group = mongoose.model('Group', GroupSchema);

// Middleware de autenticación
const authMiddleware = (req, res, next) => {
    const token = req.header('Authorization')?.replace('Bearer ', '');
    if (!token) return res.status(401).json({ error: 'Acceso denegado' });
    try {
        const decoded = jwt.verify(token, process.env.JWT_SECRET);
        req.user = decoded;
        next();
    } catch (err) {
        res.status(401).json({ error: 'Token inválido' });
    }
};

// Rutas
app.post('/api/register', async (req, res) => {
    const { email, password, name } = req.body;
    try {
        const hashedPassword = await bcrypt.hash(password, 10);
        const user = new User({ email, password: hashedPassword, name });
        await user.save();
        res.status(201).json({ message: 'Usuario registrado' });
    } catch (err) {
        res.status(400).json({ error: 'Error al registrar' });
    }
});

app.post('/api/login', async (req, res) => {
    const { email, password } = req.body;
    try {
        const user = await User.findOne({ email });
        if (!user || !await bcrypt.compare(password, user.password)) {
            return res.status(401).json({ error: 'Credenciales inválidas' });
        }
        const token = jwt.sign({ userId: user._id, name: user.name }, process.env.JWT_SECRET, { expiresIn: '1h' });
        res.json({ token, name: user.name });
    } catch (err) {
        res.status(400).json({ error: 'Error al iniciar sesión' });
    }
});

app.post('/api/availability', authMiddleware, async (req, res) => {
    const { groupId, timezone, startTime, endTime } = req.body;
    try {
        const availability = new Availability({
            userId: req.user.userId,
            groupId,
            timezone,
            startTime,
            endTime
        });
        await availability.save();
        io.emit('availabilityUpdate', { groupId });
        res.status(201).json({ message: 'Disponibilidad guardada' });
    } catch (err) {
        res.status(400).json({ error: 'Error al guardar disponibilidad' });
    }
});

app.get('/api/availabilities/:groupId', authMiddleware, async (req, res) => {
    try {
        const availabilities = await Availability.find({ groupId: req.params.groupId }).populate('userId', 'name');
        res.json(availabilities);
    } catch (err) {
        res.status(400).json({ error: 'Error al obtener disponibilidades' });
    }
});

app.post('/api/groups', authMiddleware, async (req, res) => {
    const { name, description } = req.body;
    try {
        const group = new Group({ name, description, members: [req.user.userId] });
        await group.save();
        res.status(201).json(group);
    } catch (err) {
        res.status(400).json({ error: 'Error al crear grupo' });
    }
});

app.get('/api/groups', authMiddleware, async (req, res) => {
    try {
        const groups = await Group.find({ members: req.user.userId });
        res.json(groups);
    } catch (err) {
        res.status(400).json({ error: 'Error al obtener grupos' });
    }
});

app.post('/api/groups/:groupId/join', authMiddleware, async (req, res) => {
    try {
        const group = await Group.findById(req.params.groupId);
        if (!group) return res.status(404).json({ error: 'Grupo no encontrado' });
        if (!group.members.includes(req.user.userId)) {
            group.members.push(req.user.userId);
            await group.save();
            io.emit('groupUpdate', { groupId: group._id });
        }
        res.json({ message: 'Unido al grupo' });
    } catch (err) {
        res.status(400).json({ error: 'Error al unirse al grupo' });
    }
});

app.post('/api/groups/:groupId/mission', authMiddleware, async (req, res) => {
    const { missionTime } = req.body;
    try {
        const group = await Group.findById(req.params.groupId);
        if (!group) return res.status(404).json({ error: 'Grupo no encontrado' });
        group.missionTime = new Date(missionTime);
        await group.save();
        io.emit('missionUpdate', { groupId: req.params.groupId, missionTime });
        res.json({ message: 'Misión programada' });
    } catch (err) {
        res.status(400).json({ error: 'Error al programar misión' });
    }
});

// WebSockets
io.on('connection', socket => {
    console.log('Cliente conectado');
    socket.on('disconnect', () => console.log('Cliente desconectado'));
});

// Iniciar servidor
server.listen(process.env.PORT || 5000, () => {
    console.log(`Servidor corriendo en puerto ${process.env.PORT || 5000}`);
});
